<?php

declare(strict_types=1);

namespace App\Audit;

use App\Jobs\AnalyzePage;
use App\Jobs\FinalizeAudit;
use App\Jobs\FusionReconcilePage;
use App\Jobs\MachineAnalyzePage;
use App\Jobs\RenderPage;
use App\Jobs\WriteWorkbook;
use App\Models\AiAgent;
use App\Models\AiProviderConfig;
use App\Models\Export;
use App\Models\PageVerification;
use App\Models\Publication;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuditCoordinator
{
    public function start(Publication $publication): void
    {
        if (in_array($publication->status, ['review_ready', 'final'], true)) {
            return;
        }
        $mode = $publication->audit_mode ?? 'ai';
        $readyAgents = collect();
        $config = null;
        if ($mode !== 'machine') {
            $readyAgents = AiAgent::ready()->where('slot', '<=', AiAgent::MAX_PRIMARY_SLOTS)->get();
            $config = $publication->aiConfig ?? AiProviderConfig::latest('id')->first();
            if ($readyAgents->isEmpty() && ! $config?->vision_tested_at) {
                throw ValidationException::withMessages(['ai' => 'Admin perlu mengatur AI Agent dan meluluskan uji pembacaan gambar pada minimal 1 agent terlebih dahulu.']);
            }
        }
        if ($publication->locked_at) {
            throw ValidationException::withMessages(['audit' => 'Hasil final sudah dikunci.']);
        }

        // Strict 1-by-1 execution: if another publication is already actively running, enqueue this one into batch_queued
        $otherActiveExists = Publication::where('id', '!=', $publication->id)
            ->whereIn('status', [
                'processing',
                'processing_machine',
                'processing_ai',
                'reconciling',
                'cross_checking',
                'exporting',
            ])
            ->where('updated_at', '>=', now()->subMinutes(30))
            ->exists();

        if ($otherActiveExists) {
            $ctx = $publication->context ?? [];
            $ctx['batch_queued_at'] = $ctx['batch_queued_at'] ?? now()->toIso8601String();
            $ctx['batch_order'] = $ctx['batch_order'] ?? (int) (microtime(true) * 1000);
            $publication->update([
                'ai_config_id' => $config?->id,
                'status' => 'batch_queued',
                'context' => $ctx,
                'error' => null,
            ]);
            if ($readyAgents->isNotEmpty()) {
                $syncData = [];
                foreach ($readyAgents as $ag) {
                    $syncData[$ag->id] = ['config_hash_at_start' => (string) $ag->config_hash];
                }
                $publication->aiAgents()->sync($syncData);
            }

            return;
        }

        $allPagesRenderedAndMachineDone = $mode === 'fusion'
            && $publication->pages()->count() === $publication->page_count
            && ! $publication->pages()
                ->where(fn ($q) => $q->whereNull('image_path')->orWhereNull('machine_status')->orWhere('machine_status', '!=', 'complete'))
                ->exists();

        $initialStatus = match ($mode) {
            'fusion' => $allPagesRenderedAndMachineDone ? 'processing_ai' : 'processing_machine',
            'machine' => 'processing_machine',
            default => 'processing',
        };

        $ctx = $publication->context ?? [];
        unset($ctx['batch_queued_at'], $ctx['batch_order']);

        $publication->update([
            'ai_config_id' => $config?->id,
            'status' => $initialStatus,
            'context' => $ctx,
            'fusion_summary' => $mode === 'fusion' ? ['stage' => $allPagesRenderedAndMachineDone ? 'ai' : 'machine'] : null,
            'started_at' => $publication->started_at ?? now(),
            'error' => null,
        ]);
        if ($readyAgents->isNotEmpty()) {
            $syncData = [];
            foreach ($readyAgents as $ag) {
                $syncData[$ag->id] = ['config_hash_at_start' => (string) $ag->config_hash];
            }
            $publication->aiAgents()->sync($syncData);
        }

        $incompletePages = $publication->pages()->where('status', '!=', 'complete')->get();
        if ($incompletePages->isEmpty()) {
            $this->afterPage($publication->fresh());

            return;
        }

        if ($allPagesRenderedAndMachineDone) {
            // Stage 1 (Mesin) & PDF render already complete: resume directly at Stage 2 (Vision AI)
            $publication->pages()->where('status', '!=', 'complete')->update([
                'status' => 'ready',
                'error' => null,
                'fusion_status' => null,
            ]);
            foreach ($incompletePages as $page) {
                AnalyzePage::dispatch($page->id, $page->dpi)->onQueue('ai');
            }
        } else {
            // Mark all incomplete pages as pending synchronously BEFORE dispatching so afterPage() never sees 0 active pages
            $publication->pages()->where('status', '!=', 'complete')->update([
                'status' => 'pending',
                'error' => null,
            ]);
            foreach ($incompletePages as $page) {
                RenderPage::dispatch($page->id);
            }
        }

        AuditLogger::record('audit.started', $publication, [
            'mode' => $mode,
            'model' => $readyAgents->first()?->model ?? $config?->model ?? 'Machine Heuristic',
            'active_agents_count' => $readyAgents->count(),
        ]);
    }

    public function retry(Publication $publication, int $physicalPage): void
    {
        if (! $publication->ai_config_id && ! $publication->aiAgents()->exists() && ! AiAgent::ready()->exists()) {
            throw ValidationException::withMessages(['audit' => 'Mulai pemeriksaan terlebih dahulu.']);
        }
        $page = $publication->pages()->where('physical_page', $physicalPage)->firstOrFail();
        if ($page->status === 'complete') {
            return;
        }
        $page->update(['status' => 'pending', 'error' => null]);
        $publication->update(['status' => 'processing', 'error' => null]);
        RenderPage::dispatch($page->id);
    }

    public function rebindAi(Publication $publication): void
    {
        DB::transaction(function () use ($publication): void {
            $pub = Publication::whereKey($publication->id)->lockForUpdate()->firstOrFail();
            if ($pub->status !== 'partial_failure' || $pub->locked_at || $pub->pages()->whereIn('status', ['rendering', 'analyzing'])->exists()) {
                throw ValidationException::withMessages(['ai' => 'Pergantian koneksi hanya dapat dilakukan setelah proses gagal dan tidak ada halaman yang sedang dibaca.']);
            }
            $readyAgents = AiAgent::ready()->where('slot', '<=', AiAgent::MAX_PRIMARY_SLOTS)->get();
            $config = AiProviderConfig::latest('id')->first();
            if ($readyAgents->isEmpty() && ! $config?->vision_tested_at) {
                throw ValidationException::withMessages(['ai' => 'Admin perlu menyimpan dan meluluskan uji koneksi AI baru terlebih dahulu.']);
            }
            $old = $pub->ai_config_id;
            $pub->update(['ai_config_id' => $config?->id]);
            if ($readyAgents->isNotEmpty()) {
                $syncData = [];
                foreach ($readyAgents as $ag) {
                    $syncData[$ag->id] = ['config_hash_at_start' => (string) $ag->config_hash];
                }
                $pub->aiAgents()->sync($syncData);
            }
            AuditLogger::record('audit.provider_rebound', $pub, [
                'previous_config_id' => $old,
                'new_config_id' => $config?->id,
                'model' => $readyAgents->first()?->model ?? $config?->model,
                'active_agents_count' => $readyAgents->count(),
                'completed_pages_preserved' => $pub->pages()->where('status', 'complete')->count(),
            ]);
        });
        $publication->refresh()->unsetRelation('aiConfig')->unsetRelation('aiAgents');
        $this->start($publication);
    }

    public function canComplete(Publication $publication): bool
    {
        $mode = $publication->audit_mode ?? 'ai';
        $pagesComplete = $publication->pages()->count() === $publication->page_count && ! $publication->pages()->where(fn ($q) => $q->where('status', '!=', 'complete')->orWhereNull('result')->orWhereNull('completed_at'))->exists();
        if (! $pagesComplete) {
            return false;
        }

        if ($mode === 'fusion') {
            $pendingFusion = $publication->pages()
                ->where(fn ($q) => $q->whereNull('fusion_status')->orWhere('fusion_status', '!=', 'done'))
                ->exists();
            if ($pendingFusion) {
                return false;
            }
        }

        $pendingVerifications = PageVerification::whereIn('publication_page_id', $publication->pages()->select('id'))
            ->whereIn('status', ['pending', 'running'])
            ->exists();

        return ! $pendingVerifications;
    }

    public function afterMachinePage(Publication $publication): void
    {
        $publication->touch();

        $allMachineComplete = $publication->pages()->count() === $publication->page_count
            && ! $publication->pages()
                ->where(fn ($q) => $q->whereNull('machine_status')->orWhere('machine_status', '!=', 'complete'))
                ->where('status', '!=', 'failed')
                ->exists();

        if (! $allMachineComplete) {
            if ($publication->status !== 'processing_machine') {
                $publication->update(['status' => 'processing_machine']);
            }
            return;
        }

        // Tahap 1 (Mesin) selesai 100%!
        // SEKARANG: Berpindah ke Tahap 2 (Vision AI)
        $publication->update([
            'status' => 'processing_ai',
            'fusion_summary' => array_merge((array) ($publication->fusion_summary ?? []), ['stage' => 'ai']),
        ]);

        AuditLogger::record('audit.fusion_machine_completed', $publication);

        // Picu pemeriksaan Vision AI untuk seluruh halaman yang belum complete
        foreach ($publication->pages()->where('status', '!=', 'complete')->get() as $page) {
            AnalyzePage::dispatch($page->id, $page->dpi)->onQueue('ai');
        }
    }

    public function afterAiPage(Publication $publication): void
    {
        $mode = $publication->audit_mode ?? 'ai';
        if ($mode !== 'fusion') {
            $this->afterPage($publication);
            return;
        }

        $hasActiveAiPages = $publication->pages()
            ->whereIn('status', ['pending', 'ready', 'rendering', 'analyzing'])
            ->exists();

        if ($hasActiveAiPages || $publication->pages()->count() < $publication->page_count) {
            if ($publication->status !== 'processing_ai') {
                $publication->update(['status' => 'processing_ai']);
            }
            return;
        }

        // Tahap 2 (Vision AI) selesai untuk seluruh halaman yang bisa diproses!
        // SEKARANG: Berpindah ke Tahap 3 (Rekonsiliasi & Adjudikasi)
        $publication->update([
            'status' => 'reconciling',
            'fusion_summary' => array_merge((array) ($publication->fusion_summary ?? []), ['stage' => 'reconciling']),
        ]);

        AuditLogger::record('audit.fusion_ai_completed', $publication);

        // Picu Rekonsiliasi Fusion untuk setiap halaman
        $dispatched = 0;
        foreach ($publication->pages as $page) {
            if ($page->status === 'complete') {
                $page->update(['fusion_status' => 'reconciling']);
                FusionReconcilePage::dispatch($page->id);
                $dispatched++;
            } else {
                $page->update(['fusion_status' => 'done']);
            }
        }

        if ($dispatched === 0) {
            $this->afterPage($publication->fresh());
        }
    }

    public function afterPage(Publication $publication): void
    {
        $publication->touch();

        if ($this->canComplete($publication)) {
            FinalizeAudit::dispatch($publication->id);

            return;
        }

        $mode = $publication->audit_mode ?? 'ai';
        if ($mode === 'fusion') {
            $stage = $publication->fusion_summary['stage'] ?? 'machine';
            if ($stage === 'machine') {
                if ($publication->status !== 'processing_machine') {
                    $publication->update(['status' => 'processing_machine']);
                }
                return;
            }
            if ($stage === 'ai') {
                $this->afterAiPage($publication);
                return;
            }
            if ($stage === 'reconciling') {
                $hasPendingFusion = $publication->pages()
                    ->where(fn ($q) => $q->whereNull('fusion_status')->orWhere('fusion_status', '!=', 'done'))
                    ->exists();
                if ($hasPendingFusion) {
                    $hasFusionJobs = \Illuminate\Support\Facades\DB::table('jobs')
                        ->whereIn('queue', ['machine', 'ai'])
                        ->exists();

                    if (! $hasFusionJobs) {
                        // Tidak ada job aktif di antrean: selesaikan status halaman yang tertinggal agar tidak deadlock
                        $publication->pages()
                            ->where(fn ($q) => $q->whereNull('fusion_status')->orWhere('fusion_status', '!=', 'done'))
                            ->update(['fusion_status' => 'done']);
                    } else {
                        if ($publication->status !== 'reconciling') {
                            $publication->update(['status' => 'reconciling']);
                        }
                        return;
                    }
                }
            }
        }

        // Check if any pages or cross-verifications are still actively processing
        $hasActivePages = $publication->pages()
            ->whereIn('status', ['pending', 'ready', 'rendering', 'analyzing'])
            ->exists();

        $hasPendingVerifications = PageVerification::whereIn('publication_page_id', $publication->pages()->select('id'))
            ->whereIn('status', ['pending', 'running'])
            ->exists();

        if ($hasActivePages || $hasPendingVerifications) {
            // Keep status as processing while remaining pages are still in progress
            if (! in_array($publication->status, ['processing', 'processing_machine', 'processing_ai', 'reconciling', 'final', 'exporting', 'review_ready'], true)) {
                $publication->update(['status' => 'processing', 'error' => null]);
            }

            return;
        }

        // All pages have completed their runs (no active pages or verifications remaining).
        // Only now, if there are incomplete or failed pages, set partial_failure at the end of the entire batch!
        $hasIncomplete = $publication->pages()
            ->where('status', '!=', 'complete')
            ->exists();

        if ($hasIncomplete && $publication->status !== 'partial_failure' && ! in_array($publication->status, ['final', 'exporting', 'review_ready'], true)) {
            $publication->update([
                'status' => 'partial_failure',
                'error' => 'Pemeriksaan selesai untuk sebagian besar halaman. Ada halaman yang membutuhkan verifikasi manual.',
            ]);
            $this->advanceBatchQueue();
        }
    }

    /**
     * Enqueue up to 15 publications into the sequential server-side batch queue.
     *
     * @param  array<int, string>  $publicationIds
     * @return array{queued_count: int, started_publication_id: ?string}
     */
    public function enqueueBatch(array $publicationIds, ?string $auditMode = null): array
    {
        $readyAgents = AiAgent::ready()->where('slot', '<=', AiAgent::MAX_PRIMARY_SLOTS)->get();
        $config = AiProviderConfig::latest('id')->first();
        $hasAiReady = $readyAgents->isNotEmpty() || $config?->vision_tested_at !== null;

        $queuedCount = 0;
        $orderBase = (int) (microtime(true) * 1000);

        foreach (array_slice(array_values(array_unique($publicationIds)), 0, 15) as $idx => $pubId) {
            $pub = Publication::find($pubId);
            if (! $pub || $pub->locked_at || in_array($pub->status, ['final', 'processing', 'processing_machine', 'processing_ai', 'reconciling', 'cross_checking', 'exporting'], true)) {
                continue;
            }

            $effectiveMode = $auditMode && in_array($auditMode, ['ai', 'machine', 'fusion'], true)
                ? $auditMode
                : ($pub->audit_mode ?? 'ai');

            if ($effectiveMode !== 'machine' && ! $hasAiReady) {
                throw ValidationException::withMessages([
                    'ai' => 'Admin perlu mengatur AI Agent dan meluluskan uji pembacaan gambar pada minimal 1 agent sebelum menjalankan pemeriksaan AI.',
                ]);
            }

            $ctx = $pub->context ?? [];
            $ctx['batch_queued_at'] = now()->toIso8601String();
            $ctx['batch_order'] = $orderBase + $idx;

            $pub->update([
                'status' => 'batch_queued',
                'audit_mode' => $effectiveMode,
                'context' => $ctx,
                'error' => null,
            ]);

            $queuedCount++;
        }

        $started = $this->advanceBatchQueue();

        return [
            'queued_count' => $queuedCount,
            'started_publication_id' => $started?->id,
        ];
    }

    public function cancelBatchQueue(Publication $publication): void
    {
        if ($publication->status !== 'batch_queued') {
            return;
        }

        $ctx = $publication->context ?? [];
        unset($ctx['batch_queued_at'], $ctx['batch_order']);

        $hasCompletedPages = $publication->pages()->where('status', 'complete')->exists();
        $publication->update([
            'status' => $hasCompletedPages ? 'partial_failure' : 'draft',
            'context' => $ctx,
        ]);
    }

    /**
     * Advance the sequential batch queue if no publication is currently actively running.
     * Ensures 1-by-1 execution and never blocks manual single-publication runs.
     */
    public function advanceBatchQueue(): ?Publication
    {
        try {
            return \Illuminate\Support\Facades\Cache::lock('audit-batch-sequencer', 15)->block(5, function (): ?Publication {
                $activeRunningExists = Publication::whereIn('status', [
                    'processing',
                    'processing_machine',
                    'processing_ai',
                    'reconciling',
                    'cross_checking',
                    'exporting',
                ])
                    ->where('updated_at', '>=', now()->subMinutes(30))
                    ->exists();

                if ($activeRunningExists) {
                    return null;
                }

                $candidates = Publication::where('status', 'batch_queued')
                    ->whereNull('locked_at')
                    ->get()
                    ->sortBy(fn (Publication $p) => $p->context['batch_order'] ?? $p->created_at?->timestamp ?? 0);

                /** @var ?Publication $next */
                $next = $candidates->first();
                if (! $next) {
                    return null;
                }

                $ctx = $next->context ?? [];
                unset($ctx['batch_queued_at'], $ctx['batch_order']);
                $next->update(['context' => $ctx]);

                try {
                    $this->start($next);

                    return $next->fresh();
                } catch (\Throwable $e) {
                    $next->update([
                        'status' => 'draft',
                        'error' => 'Gagal memulai dari antrean batch: '.$e->getMessage(),
                    ]);

                    return null;
                }
            });
        } catch (\Throwable) {
            return null;
        }
    }

    public function export(Publication $publication, ?int $userId = null, string $format = 'standard'): Export
    {
        if (! $this->canComplete($publication) || ! in_array($publication->status, ['exporting', 'review_ready', 'final'], true)) {
            throw ValidationException::withMessages(['export' => 'Semua halaman dan pemeriksaan silang harus selesai sebelum Excel dibuat.']);
        }
        $export = DB::transaction(function () use ($publication, $userId, $format): Export {
            $pub = Publication::whereKey($publication->id)->lockForUpdate()->firstOrFail();

            return $pub->exports()->create([
                'version' => ($pub->exports()->max('version') ?? 0) + 1,
                'publication_revision' => $pub->revision,
                'created_by' => $userId,
                'validation' => ['export_format' => $format],
            ]);
        });
        WriteWorkbook::dispatch($export->id);

        return $export;
    }

    public function coverage(Publication $publication): array
    {
        $pages = $publication->pages()->get()->keyBy('physical_page');
        $unchecked = [];
        for ($number = 1; $number <= $publication->page_count; $number++) {
            $page = $pages->get($number);
            if (! $page || $page->status !== 'complete' || $page->result === null || $page->completed_at === null) {
                $unchecked[] = ['physical_page' => $number, 'status' => $page?->status ?? 'missing', 'error' => $page?->error];
            }
        }

        return ['checked_pages' => $publication->page_count - count($unchecked), 'total_pages' => $publication->page_count, 'unchecked_pages' => $unchecked];
    }

    public function exportPartial(Publication $publication, int $userId): Export
    {
        $export = DB::transaction(function () use ($publication, $userId): Export {
            $pub = Publication::whereKey($publication->id)->lockForUpdate()->firstOrFail();
            if ($pub->locked_at || $this->coverage($pub)['checked_pages'] === 0) {
                throw ValidationException::withMessages(['export' => 'Excel sementara memerlukan hasil halaman yang sudah selesai dan publikasi yang belum dikunci.']);
            }

            return $pub->exports()->create([
                'version' => ($pub->exports()->max('version') ?? 0) + 1,
                'publication_revision' => $pub->revision, 'created_by' => $userId,
                'validation' => ['mode' => 'partial'],
            ]);
        });
        try {
            WriteWorkbook::dispatchSync($export->id);
        } catch (\Throwable $exception) {
            $export->update(['status' => 'failed', 'error' => 'Excel sementara gagal dibuat. Hasil pemeriksaan tetap tersimpan; coba lagi.']);
            report($exception);
            throw ValidationException::withMessages(['export' => 'Excel sementara gagal dibuat. Hasil pemeriksaan tetap tersimpan; coba lagi.']);
        }

        return $export->refresh();
    }
}
