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
            if (config('audit.chapter_map_enabled', true)) {
                $rawPages = $pub->pages()->orderBy('physical_page')->get()->toArray();
                $chapterMap = app(\App\Audit\ChapterMapBuilder::class)->build($rawPages);
                $manifest = $pub->manifest ?? [];
                $manifest['chapter_map'] = $chapterMap;
                $pub->update(['manifest' => $manifest]);
            }
            foreach ($checks->run($pub->pages()->get()->pluck('result')->all()) as $finding) {
                $writer->store($pub, $finding);
            }
            if (config('audit.numeric_checks_enabled', true)) {
                $rawPagesForNumeric = $pub->pages()->orderBy('physical_page')->get()->toArray();
                $numericChecker = app(\App\Audit\Numeric\TableNumericChecker::class);
                foreach ($numericChecker->run($rawPagesForNumeric) as $numericFinding) {
                    $writer->store($pub, $numericFinding);
                }
            }
            if (config('audit.chapter_crosscheck_enabled', true)) {
                $allRawPages = $pub->pages()->orderBy('physical_page')->get()->toArray();
                $manifest = $pub->manifest ?? [];
                $chapterMap = $manifest['chapter_map'] ?? null;
                $claimExtractor = app(\App\Audit\Claims\NarrativeClaimExtractor::class);

                if (! empty($chapterMap['chapters'])) {
                    foreach ($chapterMap['chapters'] as $ch) {
                        $start = $ch['physical_start'] ?? null;
                        $end = $ch['physical_end'] ?? null;
                        if ($start !== null && $end !== null) {
                            $chPages = array_filter($allRawPages, fn ($p) => ($p['physical_page'] >= $start && $p['physical_page'] <= $end));
                            foreach ($claimExtractor->evaluate($chPages) as $crossFinding) {
                                $writer->store($pub, $crossFinding);
                            }
                        }
                    }
                } else {
                    foreach ($claimExtractor->evaluate($allRawPages) as $crossFinding) {
                        $writer->store($pub, $crossFinding);
                    }
                }
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
        app(AuditCoordinator::class)->advanceBatchQueue();
    }
}
