<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Audit\FindingWriter;
use App\Audit\FusionReconciler;
use App\Models\FusionCandidate;
use App\Models\PublicationPage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class FusionReconcilePage implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public function __construct(public int $pageId)
    {
        $this->onQueue('machine');
    }

    public static function dispatchIfReady(int $pageId): void
    {
        $page = PublicationPage::find($pageId);
        if (! $page) {
            return;
        }

        // Siap rekonsiliasi jika mesin sudah complete DAN ai sudah complete
        if ($page->machine_status === 'complete' && $page->status === 'complete') {
            self::dispatch($pageId);
        }
    }

    public function handle(FusionReconciler $reconciler, FindingWriter $writer): void
    {
        $page = PublicationPage::findOrFail($this->pageId);
        $publication = $page->publication;

        $results = $reconciler->reconcile($page);
        $agreed = $results['agreed'];
        $machineOnly = $results['machine_only'];
        $aiOnly = $results['ai_only'];

        // 1. Tulis temuan yang sepakat (Agreed) langsung ke tabel final findings
        foreach ($agreed as $pair) {
            $candidate = $pair['machine'];
            $writer->store($publication, [
                'rule_code' => $candidate->rule_code,
                'severity' => $candidate->severity,
                'source' => 'fusion',
                'detected_by' => 'both',
                'fusion_status' => 'agreed',
                'confidence' => 0.95,
                'observed' => $candidate->observed,
                'expected' => $candidate->expected,
                'recommendation' => $candidate->recommendation,
                'section' => $page->page_type,
                'evidences' => $candidate->evidences ?? [['page' => $page->physical_page, 'quote' => $candidate->observed, 'bbox' => null]],
            ]);
        }

        // 2. Jika ada selisih (machine_only atau ai_only), kirim ke Adjudikasi AI
        $hasDiscrepancies = (! empty($machineOnly)) || (! empty($aiOnly));
        if ($hasDiscrepancies) {
            $page->update(['fusion_status' => 'adjudicating']);
            AdjudicatePage::dispatch($page->id)->onQueue('ai');
        } else {
            $page->update(['fusion_status' => 'done']);
            app(\App\Audit\AuditCoordinator::class)->afterPage($publication);
        }
    }
}
