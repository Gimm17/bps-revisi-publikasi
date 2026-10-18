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
        private ?FindingWriter $writer = null
    ) {
        $this->indexer ??= new PageAnatomyIndexer;
        $this->printChecker ??= new PrintLayoutChecker;
        $this->writer ??= new FindingWriter;
    }

    /**
     * @param  array<int, string>  $scope
     */
    public function start(Publication $publication, User $actor, array $scope = ['volume', 'cover_title', 'print_layout']): PublicationCheckRun
    {
        return DB::transaction(function () use ($publication, $actor, $scope): PublicationCheckRun {
            $pub = Publication::whereKey($publication->id)->lockForUpdate()->firstOrFail();

            // Idempotency: return active run if already running
            $activeRun = $pub->checkRuns()->whereIn('status', ['pending', 'running'])->first();
            if ($activeRun) {
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

                if ($type === 'volume' && $pub->kind === 'kcda') {
                    FetchPublicationReference::dispatch($run->id);
                } elseif ($type === 'print_layout') {
                    $this->executePrintLayout($run, $check);
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

        if ($check->check_type === 'volume' && $run->publication->kind === 'kcda') {
            FetchPublicationReference::dispatch($run->id);
        } elseif ($check->check_type === 'print_layout') {
            $this->executePrintLayout($run, $check);
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
}
