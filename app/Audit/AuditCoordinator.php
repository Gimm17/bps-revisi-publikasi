<?php

declare(strict_types=1);

namespace App\Audit;

use App\Jobs\FinalizeAudit;
use App\Jobs\RenderPage;
use App\Jobs\WriteWorkbook;
use App\Models\AiProviderConfig;
use App\Models\Export;
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
        $config = $publication->aiConfig ?? AiProviderConfig::latest('id')->first();
        if (! $config?->vision_tested_at) {
            throw ValidationException::withMessages(['ai' => 'Admin perlu mengatur 9Router dan meluluskan uji pembacaan gambar terlebih dahulu.']);
        }
        if ($publication->locked_at) {
            throw ValidationException::withMessages(['audit' => 'Hasil final sudah dikunci.']);
        }
        $publication->update(['ai_config_id' => $config->id, 'status' => 'processing', 'started_at' => $publication->started_at ?? now(), 'error' => null]);
        foreach ($publication->pages()->where('status', '!=', 'complete')->get() as $page) {
            RenderPage::dispatch($page->id);
        }
        AuditLogger::record('audit.started', $publication, ['model' => $config->model]);
    }

    public function retry(Publication $publication, int $physicalPage): void
    {
        if (! $publication->ai_config_id) {
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

    public function canComplete(Publication $publication): bool
    {
        return $publication->pages()->count() === $publication->page_count && ! $publication->pages()->where(fn ($q) => $q->where('status', '!=', 'complete')->orWhereNull('result')->orWhereNull('completed_at'))->exists();
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
}
