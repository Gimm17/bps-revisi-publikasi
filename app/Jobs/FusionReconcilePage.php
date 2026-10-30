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
            $candidate = $pair['ai'] ?? $pair['machine'];
            $pair['machine']->update(['verdict' => 'confirm', 'verdict_reason' => 'Agreed by Machine and Vision AI']);
            $pair['ai']->update(['verdict' => 'confirm', 'verdict_reason' => 'Agreed by Machine and Vision AI']);

            $writer->store($publication, [
                'rule_code' => $candidate->rule_code,
                'severity' => $candidate->severity,
                'source' => 'fusion',
                'detected_by' => 'both',
                'fusion_status' => 'agreed',
                'confidence' => 0.96,
                'observed' => $candidate->observed,
                'expected' => $candidate->expected,
                'recommendation' => $candidate->recommendation,
                'section' => $page->page_type,
                'evidences' => $candidate->evidences ?? [['page' => $page->physical_page, 'quote' => $candidate->observed, 'bbox' => null]],
            ]);
        }

        // 2. Tulis temuan deterministik Mesin (machine_only) langsung ke tabel final findings
        foreach ($machineOnly as $mCandidate) {
            $mCandidate->update(['verdict' => 'confirm', 'verdict_reason' => 'Deterministic BPS Rule Evaluator']);
            $writer->store($publication, [
                'rule_code' => $mCandidate->rule_code,
                'severity' => $mCandidate->severity,
                'source' => 'fusion',
                'detected_by' => 'machine',
                'fusion_status' => 'machine_confirmed',
                'confidence' => 0.95,
                'observed' => $mCandidate->observed,
                'expected' => $mCandidate->expected,
                'recommendation' => $mCandidate->recommendation,
                'section' => $page->page_type,
                'evidences' => $mCandidate->evidences ?? [['page' => $page->physical_page, 'quote' => $mCandidate->observed, 'bbox' => null]],
            ]);
        }

        // 3. Tulis temuan visual/multimodal Vision AI (ai_only) langsung ke tabel final findings
        foreach ($aiOnly as $aCandidate) {
            $aCandidate->update(['verdict' => 'confirm', 'verdict_reason' => 'Vision AI Multimodal Analysis']);
            $writer->store($publication, [
                'rule_code' => $aCandidate->rule_code,
                'severity' => $aCandidate->severity,
                'source' => 'fusion',
                'detected_by' => 'ai',
                'fusion_status' => 'ai_confirmed',
                'confidence' => 0.90,
                'observed' => $aCandidate->observed,
                'expected' => $aCandidate->expected,
                'recommendation' => $aCandidate->recommendation,
                'section' => $page->page_type,
                'evidences' => $aCandidate->evidences ?? [['page' => $page->physical_page, 'quote' => $aCandidate->observed, 'bbox' => null]],
            ]);
        }

        $page->update(['fusion_status' => 'done']);
        app(\App\Audit\AuditCoordinator::class)->afterPage($publication);
    }
}
