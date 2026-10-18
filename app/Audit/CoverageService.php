<?php

declare(strict_types=1);

namespace App\Audit;

use App\Models\Publication;

class CoverageService
{
    /**
     * @return array{
     *   page_coverage: array{checked_pages: int, total_pages: int, complete: bool},
     *   base_crosscheck: array{complete: bool, status: string},
     *   additional_checks: array<int, array<string, mixed>>,
     *   limitations: array<int, string>,
     *   report_mode: 'partial'|'complete_with_limitations'|'complete',
     *   can_export: bool,
     *   can_lock: bool
     * }
     */
    public function summarize(Publication $publication): array
    {
        $totalPages = $publication->page_count;
        $checkedPages = $publication->pages()->where('status', 'complete')->whereNotNull('result')->count();
        $pagesComplete = $checkedPages === $totalPages && $totalPages > 0;

        $pageCoverage = [
            'checked_pages' => $checkedPages,
            'total_pages' => $totalPages,
            'complete' => $pagesComplete,
        ];

        $crossCheckComplete = in_array($publication->status, ['review_ready', 'final'], true);
        $baseCrosscheck = [
            'complete' => $crossCheckComplete,
            'status' => $crossCheckComplete ? 'completed' : ($publication->status === 'cross_checking' ? 'running' : 'pending'),
        ];

        $limitations = [];
        $additionalChecks = [];
        $allAdditionalComplete = true;
        $hasLimitations = false;

        $latestRun = $publication->checkRuns()->first();
        if ($latestRun) {
            foreach ($latestRun->checks as $check) {
                $additionalChecks[] = [
                    'id' => $check->id,
                    'check_type' => $check->check_type,
                    'execution_status' => $check->execution_status,
                    'decision' => $check->decision,
                    'error_message' => $check->error_message,
                ];

                if (in_array($check->execution_status, ['pending', 'running', 'retry_wait', 'failed'], true)) {
                    $allAdditionalComplete = false;
                }

                if ($check->decision === 'needs_verification') {
                    $hasLimitations = true;
                    if ($check->error_message) {
                        $limitations[] = $check->error_message;
                    }
                }
            }
        }

        // Determine report mode
        if (! $pagesComplete || ! $allAdditionalComplete || in_array($publication->status, ['processing', 'cross_checking', 'exporting', 'partial_failure'], true)) {
            $reportMode = 'partial';
        } elseif ($hasLimitations || ! empty($limitations)) {
            $reportMode = 'complete_with_limitations';
        } else {
            $reportMode = 'complete';
        }

        $canExport = $checkedPages > 0 && ! $publication->locked_at;

        // Can lock requires pagesComplete, no pending additional checks, no unreviewed findings, and latest export
        $hasUnreviewed = $publication->findings()->where('deleted', false)->whereIn('review_status', ['unreviewed', 'needs_verification'])->exists();
        $hasLatestExport = $publication->exports()->where('status', 'complete')->where('publication_revision', $publication->revision)->exists();

        $canLock = $pagesComplete && $allAdditionalComplete && ! $hasUnreviewed && $hasLatestExport && ! $publication->locked_at;

        return [
            'page_coverage' => $pageCoverage,
            'base_crosscheck' => $baseCrosscheck,
            'additional_checks' => $additionalChecks,
            'limitations' => array_values(array_unique($limitations)),
            'report_mode' => $reportMode,
            'can_export' => $canExport,
            'can_lock' => $canLock,
        ];
    }
}
