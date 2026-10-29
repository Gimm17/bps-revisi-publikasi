<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Audit\AdditionalCheckCoordinator;
use App\Audit\BpsWebApiClient;
use App\Audit\BraveSearchClient;
use App\Audit\PublicationBrowserClient;
use App\Audit\PublicationSearchClient;
use App\Models\PublicationCheckRun;
use App\Models\PublicationReference;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FetchPublicationReference implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 2;

    public function __construct(public string $checkRunId) {}

    public function handle(PublicationSearchClient $search, PublicationBrowserClient $browser): void
    {
        $run = PublicationCheckRun::findOrFail($this->checkRunId);
        $pub = $run->publication;
        $context = $pub->context ?? [];
        $priorYear = $pub->year - 1;

        // Layer 1 & 2: Official BPS WebAPI + Local Registry Waterfall (when not using a test mock search client)
        $resolvedViaBpsApi = false;
        if ($search instanceof BraveSearchClient) {
            try {
                $bpsClient = app(BpsWebApiClient::class);
                $identity = $bpsClient->extractCurrentPublicationIdentity($pub);
                $extractedHasValues = ! empty(array_filter($identity['observed_volumes'] ?? [], fn ($v) => $v !== null));
                $mergedContext = array_merge($context, [
                    'bps_domain' => $identity['domain_id'] ?? ($context['bps_domain'] ?? null),
                    'domain_id' => $identity['domain_id'] ?? ($context['domain_id'] ?? null),
                    'catalogue_number' => $identity['catalogue_number'] ?? ($context['catalogue_number'] ?? null),
                    'publication_number' => $identity['publication_number'] ?? ($context['publication_number'] ?? null),
                    'observed_volumes' => $extractedHasValues ? $identity['observed_volumes'] : (! empty($context['observed_volumes']) ? $context['observed_volumes'] : ($identity['observed_volumes'] ?? [])),
                    'page_map' => $identity['page_map'] ?? ($context['page_map'] ?? []),
                ]);
                $pub->update(['context' => $mergedContext]);
                $context = $mergedContext;

                $bpsRef = $bpsClient->resolvePriorReference($pub, $identity);
                if ($bpsRef !== null) {
                    PublicationReference::create([
                        'check_run_id' => $run->id,
                        'series' => $pub->kind,
                        'jurisdiction' => $pub->region,
                        'year' => $priorYear,
                        'source_url' => $bpsRef['source_url'],
                        'metadata' => $bpsRef['metadata'],
                        'provenance' => $bpsRef['provenance'] ?? 'bps_webapi',
                        'access_status' => $bpsRef['access_status'] ?? 'opened',
                        'read_status' => $bpsRef['read_status'] ?? 'unreadable',
                        'scope' => ($bpsRef['read_status'] ?? '') === 'readable' ? 'cover_only' : null,
                        'artifact_manifest' => $bpsRef['artifact_manifest'] ?? ($bpsRef['artifacts'] ?? []),
                        'fetched_at' => now(),
                    ]);
                    $resolvedViaBpsApi = true;
                }
            } catch (\Throwable) {
                // Fallback to search client if BPS WebAPI fails
            }
        }

        if (! $resolvedViaBpsApi && $pub->kind === 'kcda') {
            $query = 'Kecamatan '.$pub->region.' Dalam Angka '.$priorYear;
            $candidates = $search->search($query, 5);
            $openedCount = 0;

            foreach ($candidates as $candidate) {
                if ($openedCount >= 3) {
                    break;
                }

                $url = $candidate['url'] ?? '';
                if (! $url) {
                    continue;
                }

                $openedCount++;
                $inspect = $browser->inspect($url);

                // Context validation: regency matching
                $expectedRegency = $context['regency'] ?? null;
                $inspectedRegency = $inspect['metadata']['regency'] ?? null;
                if ($expectedRegency && $inspectedRegency && strcasecmp(trim((string) $expectedRegency), trim((string) $inspectedRegency)) !== 0) {
                    // Same named district in other regency -> reject
                    continue;
                }

                // Year matching
                $inspectedYear = $inspect['metadata']['year'] ?? null;
                if ($inspectedYear && (int) $inspectedYear !== $priorYear) {
                    continue;
                }

                PublicationReference::create([
                    'check_run_id' => $run->id,
                    'series' => $pub->kind,
                    'jurisdiction' => $pub->region,
                    'year' => $priorYear,
                    'source_url' => $url,
                    'metadata' => $inspect['metadata'],
                    'provenance' => 'official_browser',
                    'access_status' => $inspect['access_status'],
                    'read_status' => $inspect['read_status'],
                    'scope' => $inspect['read_status'] === 'readable' ? 'cover_only' : null,
                    'artifact_manifest' => $inspect['artifacts'],
                    'fetched_at' => now(),
                ]);

                if ($inspect['read_status'] === 'readable') {
                    break;
                }
            }
        }

        $volumeCheck = $run->checks()->where('check_type', 'volume')->first();
        if ($volumeCheck) {
            app(AdditionalCheckCoordinator::class)->executeVolume($run, $volumeCheck);
        }
    }
}
