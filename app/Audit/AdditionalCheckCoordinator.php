<?php

declare(strict_types=1);

namespace App\Audit;

use App\Jobs\FetchPublicationReference;
use App\Models\Publication;
use App\Models\PublicationCheck;
use App\Models\PublicationCheckRun;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class AdditionalCheckCoordinator
{
    public function __construct(
        private ?PageAnatomyIndexer $indexer = null,
        private ?PrintLayoutChecker $printChecker = null,
        private ?FindingWriter $writer = null,
        private ?CoverTitleComparator $coverComparator = null,
        private ?VolumeHistoryChecker $volumeChecker = null
    ) {
        $this->indexer ??= new PageAnatomyIndexer;
        $this->printChecker ??= new PrintLayoutChecker;
        $this->writer ??= new FindingWriter;
        $this->coverComparator ??= new CoverTitleComparator;
        $this->volumeChecker ??= new VolumeHistoryChecker;
    }

    /**
     * @param  array<int, string>  $scope
     */
    public function start(Publication $publication, User $actor, array $scope = ['volume', 'cover_title', 'print_layout']): PublicationCheckRun
    {
        return DB::transaction(function () use ($publication, $actor, $scope): PublicationCheckRun {
            $pub = Publication::whereKey($publication->id)->lockForUpdate()->firstOrFail();

            // Idempotency: return active run if already running, and advance any pending checks
            $activeRun = $pub->checkRuns()->whereIn('status', ['pending', 'running'])->first();
            if ($activeRun) {
                foreach ($activeRun->checks()->whereIn('execution_status', ['pending', 'running'])->get() as $pendingCheck) {
                    if ($pendingCheck->check_type === 'volume') {
                        if ($pub->kind === 'kcda') {
                            FetchPublicationReference::dispatch($activeRun->id);
                        } else {
                            $this->executeVolume($activeRun, $pendingCheck);
                        }
                    } elseif ($pendingCheck->check_type === 'print_layout') {
                        $this->executePrintLayout($activeRun, $pendingCheck);
                    } elseif ($pendingCheck->check_type === 'cover_title') {
                        $this->executeCoverTitle($activeRun, $pendingCheck);
                    }
                }

                return $activeRun;
            }

            $rulesHash = hash('sha256', app(RuleRegistry::class)->guidance(config('audit.target_rule_version')));

            $run = $pub->checkRuns()->create([
                'requested_by' => $actor->id,
                'mode' => 'supplemental',
                'baseline_rule_version' => $pub->rule_version,
                'target_rule_version' => config('audit.target_rule_version', 'bps-2026.10.18-v2'),
                'prompt_version' => 'page-v2',
                'pdf_sha256' => $pub->pdf_checksum,
                'rules_path' => 'audit-rules/pedoman-v2.md',
                'rules_hash' => $rulesHash,
                'scope' => $scope,
                'status' => 'running',
                'started_at' => now(),
            ]);

            foreach ($scope as $type) {
                $check = $run->checks()->create([
                    'check_type' => $type,
                    'input_hash' => hash('sha256', $run->id.'|'.$type),
                    'execution_status' => 'pending',
                ]);

                if ($type === 'volume') {
                    if ($pub->kind === 'kcda') {
                        FetchPublicationReference::dispatch($run->id);
                    } else {
                        $this->executeVolume($run, $check);
                    }
                } elseif ($type === 'print_layout') {
                    $this->executePrintLayout($run, $check);
                } elseif ($type === 'cover_title') {
                    $this->executeCoverTitle($run, $check);
                }
            }

            AuditLogger::record('audit.supplemental_started', $pub, [
                'check_run_id' => $run->id,
                'scope' => $scope,
                'target_rule_version' => $run->target_rule_version,
            ]);

            return $run;
        });
    }

    public function advance(PublicationCheckRun $run): void
    {
        $allComplete = ! $run->checks()->whereIn('execution_status', ['pending', 'running', 'retry_wait'])->exists();
        if ($allComplete && $run->status !== 'completed') {
            $hasFailed = $run->checks()->where('execution_status', 'failed')->exists();
            $run->update([
                'status' => $hasFailed ? 'failed' : 'completed',
                'completed_at' => now(),
            ]);

            AuditLogger::record('audit.supplemental_completed', $run->publication, [
                'check_run_id' => $run->id,
                'status' => $run->status,
            ]);

            $pub = $run->publication->fresh();
            if ($pub && in_array($pub->status, ['exporting', 'review_ready'], true)) {
                try {
                    app(AuditCoordinator::class)->export($pub);
                } catch (\Throwable) {
                    // Do not fail supplemental check run if publication export cannot proceed
                }
            }
        }
    }

    public function retry(PublicationCheck $check, User $actor): void
    {
        $check->update([
            'execution_status' => 'pending',
            'attempts' => $check->attempts + 1,
            'error_code' => null,
            'error_message' => null,
        ]);

        $run = $check->checkRun;
        $run->update(['status' => 'running']);

        if ($check->check_type === 'volume') {
            if ($run->publication->kind === 'kcda') {
                FetchPublicationReference::dispatch($run->id);
            } else {
                $this->executeVolume($run, $check);
            }
        } elseif ($check->check_type === 'print_layout') {
            $this->executePrintLayout($run, $check);
        } elseif ($check->check_type === 'cover_title') {
            $this->executeCoverTitle($run, $check);
        }
    }

    private function executePrintLayout(PublicationCheckRun $run, PublicationCheck $check): void
    {
        $pub = $run->publication;
        $pages = $pub->pages()->orderBy('physical_page')->get();

        if ($pages->count() < $pub->page_count || $pages->where('status', 'complete')->count() < $pub->page_count) {
            // Wait for all pages to complete
            return;
        }

        $pageResults = $pages->pluck('result')->all();
        $anatomy = $this->indexer->build($pageResults);
        $result = $this->printChecker->check($anatomy);

        foreach ($result['findings'] as $candidate) {
            $candidate['check_run_id'] = $run->id;
            $this->writer->store($pub, $candidate);
        }

        $check->update([
            'execution_status' => 'completed',
            'decision' => $result['decision'],
            'result' => $result,
            'evidences' => $result['findings'][0]['evidences'] ?? [],
            'error_message' => ! empty($result['limitations']) ? implode('; ', $result['limitations']) : null,
        ]);

        $this->advance($run);
    }

    public function executeCoverTitle(PublicationCheckRun $run, PublicationCheck $check): void
    {
        $pub = $run->publication;
        $coverPage = 1;
        $titlePage = $pub->pages()->where('page_type', 'title')->value('physical_page')
            ?? $pub->pages()->whereIn('page_type', ['title', 'catalogue'])->value('physical_page')
            ?? 3;

        $result = $this->coverComparator->compare($run, (int) $coverPage, (int) $titlePage);

        foreach ($result['findings'] as $candidate) {
            $candidate['check_run_id'] = $run->id;
            $this->writer->store($pub, $candidate);
        }

        $check->update([
            'execution_status' => 'completed',
            'decision' => $result['decision'],
            'result' => $result,
            'evidences' => $result['evidences'] ?? [],
            'error_message' => ! empty($result['limitations']) ? implode('; ', $result['limitations']) : null,
        ]);

        $this->advance($run);
    }

    public function executeVolume(PublicationCheckRun $run, PublicationCheck $check): void
    {
        $pub = $run->publication;
        $context = $pub->context ?? [];

        try {
            $bpsClient = app(BpsWebApiClient::class);
            $extracted = $bpsClient->extractCurrentPublicationIdentity($pub);
            $extractedHasValues = ! empty(array_filter($extracted['observed_volumes'] ?? [], fn ($v) => $v !== null));
            $context = array_merge($context, [
                'year' => $pub->year ?? ($extracted['year'] ?? 2026),
                'bps_domain' => $extracted['domain_id'] ?? ($context['bps_domain'] ?? null),
                'domain_id' => $extracted['domain_id'] ?? ($context['domain_id'] ?? null),
                'catalogue_number' => $extracted['catalogue_number'] ?? ($context['catalogue_number'] ?? null),
                'publication_number' => $extracted['publication_number'] ?? ($context['publication_number'] ?? null),
                'observed_volumes' => $extractedHasValues ? $extracted['observed_volumes'] : (! empty($context['observed_volumes']) ? $context['observed_volumes'] : ($extracted['observed_volumes'] ?? [])),
                'page_map' => $extracted['page_map'] ?? ($context['page_map'] ?? []),
            ]);
            $pub->update(['context' => $context]);
        } catch (\Throwable) {
            // Keep existing context if extraction fails
        }

        // Remove unreviewed stale VOLUME.HISTORY findings before storing updated ones
        $pub->findings()
            ->where('rule_code', 'VOLUME.HISTORY')
            ->where('review_status', 'unreviewed')
            ->delete();

        $reference = $run->references()->latest('id')->first();
        if ($reference) {
            $res = $this->volumeChecker->compare($context, [
                'read_status' => $reference->read_status,
                'prior_volume' => $reference->metadata['volume'] ?? null,
                'prior_year' => $reference->year ?? (($pub->year ?? 2026) - 1),
                'metadata' => array_merge($reference->metadata ?? [], [
                    'reference_id' => $reference->id,
                    'source_url' => $reference->source_url,
                    'provenance' => $reference->provenance,
                ]),
                'evidences' => $reference->artifact_manifest ?? [],
            ]);
            foreach ($res['findings'] as $candidate) {
                $candidate['check_run_id'] = $run->id;
                $this->writer->store($pub, $candidate);
            }
            $check->update([
                'execution_status' => 'completed',
                'decision' => $res['decision'],
                'result' => $res,
                'evidences' => $res['evidences'] ?? [],
                'error_message' => ! empty($res['limitations']) ? implode('; ', $res['limitations']) : null,
            ]);
        } else {
            $res = $this->volumeChecker->compare($context, [
                'read_status' => 'unreadable',
                'prior_volume' => null,
                'prior_year' => ($pub->year ?? 2026) - 1,
                'metadata' => [],
                'evidences' => [],
            ]);
            foreach ($res['findings'] as $candidate) {
                $candidate['check_run_id'] = $run->id;
                $this->writer->store($pub, $candidate);
            }
            $check->update([
                'execution_status' => 'completed',
                'decision' => $res['decision'],
                'result' => $res,
                'evidences' => [],
                'error_message' => ! empty($res['limitations']) ? implode('; ', $res['limitations']) : 'Volume edisi sebelumnya belum diverifikasi secara daring.',
            ]);
        }

        $this->advance($run);
    }
}
