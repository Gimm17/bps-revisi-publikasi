<?php

declare(strict_types=1);

namespace App\Jobs;

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
            // A job captures the current revision under the same lock as finding edits.
            $export->update(['status' => 'processing', 'publication_revision' => $pub->revision]);
            $path = 'publications/'.$pub->id.'/exports/v'.$export->version.'.xlsx';
            $validation = $writer->write($pub, $path);
            $export->update(['status' => 'complete', 'path' => $path, 'checksum' => $validation['sha256'], 'finding_count' => $validation['finding_count'], 'validation' => $validation, 'error' => null]);
            if ($pub->status === 'exporting') {
                $pub->update(['status' => 'review_ready']);
            }
            AuditLogger::record('export.completed', $pub, ['version' => $export->version]);
        });
    }

    public function failed(?\Throwable $exception): void
    {
        Export::whereKey($this->exportId)->update(['status' => 'failed', 'error' => 'Pembuatan atau validasi Excel gagal. Buat versi baru atau hubungi admin.']);
    }
}
