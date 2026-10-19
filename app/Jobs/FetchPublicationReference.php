<?php

declare(strict_types=1);

namespace App\Jobs;

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

        if ($pub->kind !== 'kcda') {
            // Only KCDA has automatic query resolution in this phase
            return;
        }

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

        $volumeCheck = $run->checks()->where('check_type', 'volume')->first();
        if ($volumeCheck) {
            app(\App\Audit\AdditionalCheckCoordinator::class)->executeVolume($run, $volumeCheck);
        }
    }
}
