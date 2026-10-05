<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Audit\AuditCoordinator;
use App\Audit\FindingWriter;
use App\Audit\NineRouterClient;
use App\Audit\PageResultValidator;
use App\Audit\PromptBuilder;
use App\Audit\RuleEvaluator;
use App\Models\AiUsageRecord;
use App\Models\PublicationPage;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AnalyzePage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 220;

    public int $uniqueFor = 900;

    public function __construct(public int $pageId, public int $dpi = 160) {}

    public function uniqueId(): string
    {
        return 'analyze-'.$this->pageId.'-'.$this->dpi;
    }

    public function backoff(): array
    {
        return [30, 90, 180];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('analysis-'.$this->pageId))->releaseAfter(15)->expireAfter(250)];
    }

    public function handle(NineRouterClient $client, PromptBuilder $prompt, PageResultValidator $validator, FindingWriter $writer, AuditCoordinator $coordinator): void
    {
        $page = PublicationPage::findOrFail($this->pageId);
        $publication = $page->publication;
        if ($page->status === 'complete' || $page->dpi !== $this->dpi) {
            return;
        }
        $page->increment('attempts');
        $page->update(['status' => 'analyzing', 'error' => null]);
        $started = microtime(true);
        try {
            $response = $client->request($publication->aiConfig, $prompt->messages($page, Storage::disk('local')->path($page->image_path)));
            $result = $validator->validate($response['result'], $page->physical_page);
            AiUsageRecord::create(['publication_id' => $publication->id, 'page_id' => $page->id, 'model' => $response['model'], 'request_id' => $response['request_id'], 'usage' => $response['usage'], 'duration_ms' => (int) ((microtime(true) - $started) * 1000), 'status' => $result['readability']]);
            if ($result['readability'] !== 'readable') {
                $page->update(['result' => $result, 'status' => 'needs_verification', 'error' => 'AI belum dapat membaca semua area dengan yakin.']);
                if ($page->dpi < config('audit.high_dpi')) {
                    $page->update(['dpi' => config('audit.high_dpi'), 'status' => 'pending']);
                    RenderPage::dispatch($page->id);
                } else {
                    $publication->update(['status' => 'partial_failure']);
                }

                return;
            }
            DB::transaction(function () use ($page, $result, $writer, $publication): void {
                $current = PublicationPage::whereKey($page->id)->lockForUpdate()->firstOrFail();
                if ($current->status === 'complete') {
                    return;
                }
                foreach ($result['findings'] as $candidate) {
                    $candidate['source'] = 'ai_visual';
                    $candidate['section'] = $result['page_type'];
                    $candidate['evidences'] = [['page' => $page->physical_page, 'quote' => $candidate['quote'] ?? '', 'bbox' => $candidate['bbox'] ?? null]];
                    $writer->store($publication, $candidate);
                }
                foreach (app(RuleEvaluator::class)->evaluate($result) as $candidate) {
                    $writer->store($publication, $candidate);
                }
                $current->update(['result' => $result, 'page_type' => $result['page_type'], 'printed_page' => $result['printed_page'], 'status' => 'complete', 'completed_at' => now(), 'error' => null]);
            });
            $coordinator->afterPage($publication);
        } catch (\Throwable $e) {
            $page->update(['error' => 'Pembacaan belum valid atau layanan AI gagal. Proses akan mencoba ulang.']);
            throw $e;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $page = PublicationPage::find($this->pageId);
        if (! $page || $page->status === 'complete') {
            return;
        }
        $page->update(['status' => 'failed', 'error' => 'Pembacaan halaman belum valid setelah beberapa percobaan. Periksa koneksi atau jalankan ulang.']);
        $page->publication->update(['status' => 'partial_failure']);
    }
}
