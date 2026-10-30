<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Audit\FindingWriter;
use App\Audit\PageTypeClassifier;
use App\Audit\RuleEvaluator;
use App\Audit\Text\SpellChecker;
use App\Models\FusionCandidate;
use App\Models\PublicationPage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class MachineAnalyzePage implements ShouldQueue
{
    use Queueable;

    public int $timeout = 45;

    public function __construct(public int $pageId)
    {
        $this->onQueue('machine');
    }

    public function handle(
        PageTypeClassifier $classifier,
        RuleEvaluator $evaluator,
        FindingWriter $writer
    ): void {
        $page = PublicationPage::findOrFail($this->pageId);
        $publication = $page->publication;
        $mode = $publication->audit_mode ?? 'ai';

        $totalPages = (int) $publication->pages()->count();
        $inferredType = $classifier->classify($page, $totalPages);
        $textLayer = (string) ($page->text_layer ?? '');

        // Bangun representasi data halaman untuk RuleEvaluator
        $pageData = [
            'physical_page' => $page->physical_page,
            'page_number' => $page->physical_page,
            'page_type' => $inferredType,
            'text' => $textLayer,
            'inventory' => [
                'tables' => $inferredType === 'table' ? [['number' => '1.1', 'title' => 'Tabel']] : [],
                'figures' => in_array($inferredType, ['figure_list'], true) ? [['title' => 'Gambar']] : [],
            ],
        ];

        // 1. Eksekusi 54 Aturan Deterministik BPS
        $rawFindings = $evaluator->evaluate($pageData);

        // 2. Eksekusi Spell Checker jika aktif
        if (config('audit.spellcheck_enabled', true) && ! empty($textLayer)) {
            $spellChecker = app(SpellChecker::class);
            $spellFinding = $spellChecker->checkPage($textLayer, $page->physical_page);
            if ($spellFinding !== null) {
                $spellFinding['section'] = $inferredType;
                $rawFindings[] = $spellFinding;
            }
        }

        // 3. Penyimpanan berdasarkan mode
        if ($mode === 'machine') {
            // Mode Mesin Mandiri: Simpan langsung ke tabel final findings
            foreach ($rawFindings as $candidate) {
                $candidate['source'] = 'machine';
                $candidate['detected_by'] = 'machine';
                $candidate['section'] = $inferredType;
                $candidate['evidences'] = [['page' => $page->physical_page, 'quote' => $candidate['quote'] ?? $candidate['observed'] ?? '', 'bbox' => null]];
                $writer->store($publication, $candidate);
            }

            $page->update([
                'page_type' => $inferredType,
                'status' => 'complete',
                'machine_status' => 'complete',
                'completed_at' => now(),
            ]);

            app(\App\Audit\AuditCoordinator::class)->afterPage($publication);
        } elseif ($mode === 'fusion') {
            // Mode Fusion: Simpan ke tabel staging fusion_candidates
            DB::transaction(function () use ($publication, $page, $inferredType, $rawFindings): void {
                foreach ($rawFindings as $f) {
                    FusionCandidate::create([
                        'publication_id' => $publication->id,
                        'publication_page_id' => $page->id,
                        'physical_page' => $page->physical_page,
                        'engine' => 'machine',
                        'rule_code' => $f['rule_code'],
                        'severity' => $f['severity'] ?? 'wajib',
                        'observed' => (string) ($f['observed'] ?? ''),
                        'expected' => (string) ($f['expected'] ?? ''),
                        'recommendation' => (string) ($f['recommendation'] ?? ''),
                        'evidences' => $f['evidences'] ?? [['page' => $page->physical_page, 'quote' => $f['observed'] ?? '', 'bbox' => null]],
                    ]);
                }

                $page->update([
                    'machine_result' => ['page_type' => $inferredType, 'findings_count' => count($rawFindings)],
                    'machine_status' => 'complete',
                ]);
            });

            // Langsung kirim halaman ini ke antrean Vision AI (pipeline paralel tanpa menunggu seluruh halaman selesai render)
            if ($page->status !== 'complete') {
                AnalyzePage::dispatch($page->id, $page->dpi)->onQueue('ai');
            }

            // Beritahu coordinator bahwa pemindaian mesin untuk halaman ini selesai
            app(\App\Audit\AuditCoordinator::class)->afterMachinePage($publication);
        }
    }
}
