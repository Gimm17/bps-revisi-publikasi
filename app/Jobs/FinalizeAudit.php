<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Audit\AuditCoordinator;
use App\Audit\CrossChecker;
use App\Audit\FindingWriter;
use App\Models\Publication;
use App\Services\AuditLogger;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class FinalizeAudit implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public int $uniqueFor = 600;

    public function __construct(public string $publicationId) {}

    public function uniqueId(): string
    {
        return $this->publicationId;
    }

    public function handle(AuditCoordinator $coordinator, CrossChecker $checks, FindingWriter $writer): void
    {
        Cache::lock('finalize-'.$this->publicationId, 240)->block(10, function () use ($coordinator, $checks, $writer): void {
            $pub = Publication::findOrFail($this->publicationId);
            if (! $coordinator->canComplete($pub) || in_array($pub->status, ['review_ready', 'final'], true)) {
                return;
            }
            $pub->update(['status' => 'cross_checking']);
            foreach ($checks->run($pub->pages()->get()->pluck('result')->all()) as $finding) {
                $writer->store($pub, $finding);
            }
            $pub->update(['status' => 'exporting', 'completed_at' => now()]);
            if (! $pub->exports()->whereIn('status', ['pending', 'processing', 'complete'])->where(fn ($q) => $q->whereNull('validation->mode')->orWhere('validation->mode', '!=', 'partial'))->exists()) {
                $coordinator->export($pub);
            }
            if (config('audit.rules_v2_enabled', false)) {
                $actor = $pub->creator ?? \App\Models\User::first();
                if ($actor) {
                    app(\App\Audit\AdditionalCheckCoordinator::class)->start($pub, $actor, ['volume', 'cover_title', 'print_layout']);
                }
            }
            AuditLogger::record('audit.cross_checks_completed', $pub);
        });
    }

    public function failed(?\Throwable $exception): void
    {
        Publication::whereKey($this->publicationId)->update(['status' => 'partial_failure', 'error' => 'Pemeriksaan silang gagal. Lanjutkan pemeriksaan untuk mencoba ulang.']);
    }
}
