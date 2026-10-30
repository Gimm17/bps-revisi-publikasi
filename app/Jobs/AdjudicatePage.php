<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Audit\AgentPoolSelector;
use App\Audit\AiRequestException;
use App\Audit\FindingWriter;
use App\Audit\FusionLearningService;
use App\Audit\NineRouterClient;
use App\Audit\RuleDocumentationService;
use App\Models\AiUsageRecord;
use App\Models\FusionCandidate;
use App\Models\PublicationPage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class AdjudicatePage implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public function __construct(public int $pageId)
    {
        $this->onQueue('ai');
    }

    public function handle(
        NineRouterClient $client,
        AgentPoolSelector $poolSelector,
        RuleDocumentationService $docs,
        FindingWriter $writer,
        FusionLearningService $learningService
    ): void {
        $page = PublicationPage::findOrFail($this->pageId);
        $publication = $page->publication;

        $unresolved = FusionCandidate::where('publication_page_id', $page->id)
            ->whereIn('category', ['machine_only', 'ai_only', 'conflict'])
            ->get();

        if ($unresolved->isEmpty()) {
            $page->update(['fusion_status' => 'done']);
            app(\App\Audit\AuditCoordinator::class)->afterPage($publication);
            return;
        }

        $endpoint = null;
        try {
            $endpoint = $poolSelector->selectAgent($publication);
        } catch (\App\Audit\AiRequestException $e) {
            if ($e->category === 'capacity' && $this->job && $this->attempts() < 3) {
                $this->release($e->retryAfter ?? 15);
                return;
            }
        } catch (\Throwable) {
            $endpoint = null;
        }

        if (! $endpoint) {
            // Jika agent tidak tersedia saat adjudikasi, jangan gagalkan publikasi: tandai semua unresolved sebagai verifikasi manual
            foreach ($unresolved as $item) {
                $writer->store($publication, [
                    'rule_code' => $item->rule_code,
                    'severity' => 'verifikasi',
                    'source' => 'fusion',
                    'detected_by' => $item->engine,
                    'fusion_status' => 'unresolved_manual_verification',
                    'observed' => $item->observed,
                    'expected' => $item->expected,
                    'recommendation' => $item->recommendation,
                    'section' => $page->page_type,
                    'evidences' => $item->evidences ?? [['page' => $page->physical_page, 'quote' => $item->observed, 'bbox' => null]],
                ]);
            }
            $page->update(['fusion_status' => 'done']);
            app(\App\Audit\AuditCoordinator::class)->afterPage($publication);
            return;
        }

        // Format discrepancy list
        $discrepancies = [];
        foreach ($unresolved as $item) {
            $doc = $docs->find($item->rule_code);
            $discrepancies[] = [
                'candidate_id' => $item->id,
                'detected_by' => $item->engine,
                'rule_code' => $item->rule_code,
                'rule_title' => $doc['title'] ?? $item->rule_code,
                'official_guideline' => $doc['spec_ref'] ?? 'Pedoman BPS 2023',
                'observed_text' => $item->observed,
                'expected' => $item->expected,
            ];
        }

        $imagePath = Storage::disk('local')->path($page->image_path);
        $imageData = base64_encode((string) @file_get_contents($imagePath));

        $promptText = "Anda adalah Hakim Ahli Evaluasi Publikasi Statistik BPS.\n"
            ."Pada Halaman Fisik {$page->physical_page}, terdapat perbedaan temuan antara Mesin Pemeriksa dan Vision AI.\n"
            ."Tugas Anda: Telaah setiap butir perbedaan berikut terhadap isi dan format halaman. Putuskan apakah temuan tersebut VALID ('confirm') atau SALAH DETEKSI ('reject').\n\n"
            ."DAFTAR BUTIR PERBEDAAN:\n".json_encode($discrepancies, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n\n"
            ."Format balasan WAJIB JSON murni tanpa markdown:\n"
            .'{"verdicts": [{"candidate_id": 1, "verdict": "confirm", "reason": "Teks jelas melanggar format BPS"}]}';

        $messages = [
            [
                'role' => 'system',
                'content' => 'Anda adalah Hakim Pemeriksa Publikasi BPS. Balas HANYA dengan objek JSON berisi array verdicts.',
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $promptText],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,'.$imageData]],
                ],
            ],
        ];

        try {
            $started = microtime(true);
            $response = $client->request($endpoint, $messages);
            $rawResult = $response['result'] ?? [];
            if (is_array($rawResult) && isset($rawResult['choices'][0]['message']['content'])) {
                $content = (string) $rawResult['choices'][0]['message']['content'];
                if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $m)) {
                    $content = $m[1];
                }
                $parsed = json_decode(trim($content), true) ?: [];
            } elseif (is_array($rawResult)) {
                $parsed = $rawResult;
            } else {
                $parsed = [];
            }
            $verdicts = $parsed['verdicts'] ?? (array_is_list($parsed) ? $parsed : []);

            AiUsageRecord::create([
                'publication_id' => $publication->id,
                'page_id' => $page->id,
                'ai_agent_id' => $endpoint->agentId(),
                'model' => $response['model'] ?? $endpoint->model(),
                'request_id' => $response['request_id'] ?? 'adjudication-'.$page->id,
                'usage' => $response['usage'] ?? ['total_tokens' => 0],
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                'status' => 'readable',
                'purpose' => 'adjudication',
            ]);

            $handledIds = [];
            foreach ($verdicts as $v) {
                if (! is_array($v) || ! isset($v['candidate_id'])) {
                    continue;
                }
                $candidate = $unresolved->firstWhere('id', (int) $v['candidate_id']);
                if (! $candidate) {
                    continue;
                }
                $handledIds[] = $candidate->id;

                $verdict = in_array($v['verdict'] ?? '', ['confirm', 'reject'], true) ? $v['verdict'] : 'confirm';
                $candidate->update([
                    'verdict' => $verdict,
                    'verdict_reason' => (string) ($v['reason'] ?? ''),
                ]);

                if ($verdict === 'confirm') {
                    $writer->store($publication, [
                        'rule_code' => $candidate->rule_code,
                        'severity' => $candidate->severity,
                        'source' => 'fusion',
                        'detected_by' => $candidate->engine,
                        'fusion_status' => $candidate->engine.'_confirmed',
                        'confidence' => 0.90,
                        'observed' => $candidate->observed,
                        'expected' => $candidate->expected,
                        'recommendation' => $candidate->recommendation,
                        'section' => $page->page_type,
                        'evidences' => $candidate->evidences ?? [['page' => $page->physical_page, 'quote' => $candidate->observed, 'bbox' => null]],
                    ]);
                }

                // Injeksi ke Auto-Learning Loop
                $learningService->ingestAdjudication($candidate, $verdict, (string) ($v['reason'] ?? ''));
            }

            // Pastikan kandidat yang terlewat dari respons adjudikasi tetap disimpan (tidak hilang secara diam-diam)
            foreach ($unresolved as $item) {
                if (in_array($item->id, $handledIds, true)) {
                    continue;
                }
                $item->update([
                    'verdict' => 'confirm',
                    'verdict_reason' => 'Auto-confirmed (fallback kelengkapan kandidat)',
                ]);
                $writer->store($publication, [
                    'rule_code' => $item->rule_code,
                    'severity' => $item->severity,
                    'source' => 'fusion',
                    'detected_by' => $item->engine,
                    'fusion_status' => $item->engine.'_confirmed',
                    'confidence' => 0.88,
                    'observed' => $item->observed,
                    'expected' => $item->expected,
                    'recommendation' => $item->recommendation,
                    'section' => $page->page_type,
                    'evidences' => $item->evidences ?? [['page' => $page->physical_page, 'quote' => $item->observed, 'bbox' => null]],
                ]);
            }
        } catch (\Throwable $e) {
            // Fallback graceful: jika AI adjudikasi gagal/timeout, masukkan ke findings dengan severity asli
            foreach ($unresolved as $item) {
                $item->update([
                    'verdict' => 'confirm',
                    'verdict_reason' => 'Fallback timeout adjudikasi',
                ]);
                $writer->store($publication, [
                    'rule_code' => $item->rule_code,
                    'severity' => $item->severity ?: 'verifikasi',
                    'source' => 'fusion',
                    'detected_by' => $item->engine,
                    'fusion_status' => 'adjudication_timeout_verification',
                    'observed' => $item->observed,
                    'expected' => $item->expected,
                    'recommendation' => $item->recommendation,
                    'section' => $page->page_type,
                    'evidences' => $item->evidences ?? [['page' => $page->physical_page, 'quote' => $item->observed, 'bbox' => null]],
                ]);
            }
        }

        $page->update(['fusion_status' => 'done']);
        app(\App\Audit\AuditCoordinator::class)->afterPage($publication);
    }

    public function failed(?\Throwable $exception): void
    {
        $page = PublicationPage::find($this->pageId);
        if ($page && $page->fusion_status !== 'done') {
            $publication = $page->publication;
            $writer = app(FindingWriter::class);
            $unresolved = FusionCandidate::where('publication_page_id', $page->id)
                ->whereNull('verdict')
                ->whereIn('category', ['machine_only', 'ai_only', 'conflict'])
                ->get();
            foreach ($unresolved as $item) {
                $item->update([
                    'verdict' => 'confirm',
                    'verdict_reason' => 'Fallback job failed',
                ]);
                $writer->store($publication, [
                    'rule_code' => $item->rule_code,
                    'severity' => $item->severity ?: 'verifikasi',
                    'source' => 'fusion',
                    'detected_by' => $item->engine,
                    'fusion_status' => 'adjudication_timeout_verification',
                    'observed' => $item->observed,
                    'expected' => $item->expected,
                    'recommendation' => $item->recommendation,
                    'section' => $page->page_type,
                    'evidences' => $item->evidences ?? [['page' => $page->physical_page, 'quote' => $item->observed, 'bbox' => null]],
                ]);
            }
            $page->update(['fusion_status' => 'done']);
            app(\App\Audit\AuditCoordinator::class)->afterPage($publication);
        }
    }
}
