<?php

declare(strict_types=1);

namespace App\Audit;

use App\Jobs\FinalizeAudit;
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
        $readyAgents = AiAgent::ready()->get();
        $config = $publication->aiConfig ?? AiProviderConfig::latest('id')->first();
        if ($readyAgents->isEmpty() && ! $config?->vision_tested_at) {
            throw ValidationException::withMessages(['ai' => 'Admin perlu mengatur AI Agent dan meluluskan uji pembacaan gambar pada minimal 1 agent terlebih dahulu.']);
        }
        if ($publication->locked_at) {
            throw ValidationException::withMessages(['audit' => 'Hasil final sudah dikunci.']);
        }
        $publication->update([
            'ai_config_id' => $config?->id,
            'status' => 'processing',
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
        foreach ($publication->pages()->where('status', '!=', 'complete')->get() as $page) {
            RenderPage::dispatch($page->id);
        }
        AuditLogger::record('audit.started', $publication, [
            'model' => $readyAgents->first()?->model ?? $config?->model,
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
            $readyAgents = AiAgent::ready()->get();
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
        $this->afterPage($publication);
    }

    public function canComplete(Publication $publication): bool
    {
        $pagesComplete = $publication->pages()->count() === $publication->page_count && ! $publication->pages()->where(fn ($q) => $q->where('status', '!=', 'complete')->orWhereNull('result')->orWhereNull('completed_at'))->exists();
        if (! $pagesComplete) {
            return false;
        }

        $pendingVerifications = PageVerification::whereIn('publication_page_id', $publication->pages()->select('id'))
            ->whereIn('status', ['pending', 'running'])
            ->exists();

        return ! $pendingVerifications;
    }

    public function afterPage(Publication $publication): void
    {
        if ($this->canComplete($publication)) {
            FinalizeAudit::dispatch($publication->id);
        }
    }

    public function export(Publication $publication, ?int $userId = null): Export
    {
        if (! $this->canComplete($publication) || ! in_array($publication->status, ['exporting', 'review_ready', 'final'], true)) {
            throw ValidationException::withMessages(['export' => 'Semua halaman dan pemeriksaan silang harus selesai sebelum Excel dibuat.']);
        }
        $export = DB::transaction(function () use ($publication, $userId): Export {
            $pub = Publication::whereKey($publication->id)->lockForUpdate()->firstOrFail();

            return $pub->exports()->create(['version' => ($pub->exports()->max('version') ?? 0) + 1, 'publication_revision' => $pub->revision, 'created_by' => $userId]);
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
