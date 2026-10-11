<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Audit\AuditCoordinator;
use App\Audit\WorkbookWriter;
use App\Models\Export;
use App\Models\Publication;
use App\Services\AuditLogger;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class WriteWorkbook implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public int $uniqueFor = 600;

    public function __construct(public int $exportId) {}

    public function uniqueId(): string
    {
        return (string) $this->exportId;
    }

    public function handle(WorkbookWriter $writer): void
    {
        DB::transaction(function () use ($writer) {
            $export = Export::whereKey($this->exportId)->lockForUpdate()->firstOrFail();
            if ($export->status === 'complete') {
                return;
            }
            $pub = Publication::whereKey($export->publication_id)->lockForUpdate()->firstOrFail();
            $partial = ($export->validation['mode'] ?? 'full') === 'partial';
            $coverage = app(AuditCoordinator::class)->coverage($pub);
            if (! $partial && (! app(AuditCoordinator::class)->canComplete($pub) || ! in_array($pub->status, ['exporting', 'review_ready', 'final'], true))) {
                throw new \RuntimeException('Pemeriksaan belum lengkap untuk ekspor penuh.');
            }
            $coverage += ['mode' => $partial ? 'partial' : 'full', 'cross_checks_complete' => ! $partial, 'snapshot_at' => now()->toISOString()];
            // A job captures the current revision under the same lock as finding edits.
            $export->update(['status' => 'processing', 'publication_revision' => $pub->revision]);
            $path = 'publications/'.$pub->id.'/exports/v'.$export->version.'.xlsx';
            $validation = $writer->write($pub, $path, $coverage);
            $export->update(['status' => 'complete', 'path' => $path, 'checksum' => $validation['sha256'], 'finding_count' => $validation['finding_count'], 'validation' => $validation, 'error' => null]);
            if (! $partial && $pub->status === 'exporting') {
                $pub->update(['status' => 'review_ready']);
            }
            AuditLogger::record('export.completed', $pub, ['version' => $export->version, 'mode' => $coverage['mode'], 'checked_pages' => $coverage['checked_pages']]);
        });
    }

    public function failed(?\Throwable $exception): void
    {
        Export::whereKey($this->exportId)->update(['status' => 'failed', 'error' => 'Pembuatan atau validasi Excel gagal. Buat versi baru atau hubungi admin.']);
    }
}
