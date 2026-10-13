<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Audit\AiRequestException;
use App\Audit\AuditCoordinator;
use App\Audit\FindingWriter;
use App\Audit\NineRouterClient;
use App\Audit\PageResultValidator;
use App\Audit\PromptBuilder;
use App\Audit\RuleEvaluator;
use App\Models\AiUsageRecord;
use App\Models\Publication;
use App\Models\PublicationPage;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AnalyzePage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public ?int $attemptBaseline = null;

    public ?int $usageBaseline = null;

    public int $timeout = 220;

    public int $uniqueFor = 15000;

    public function __construct(public int $pageId, public int $dpi = 160)
    {
        $this->attemptBaseline = (int) PublicationPage::whereKey($pageId)->value('attempts');
        $this->usageBaseline = (int) AiUsageRecord::where('page_id', $pageId)->max('id');
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(4);
    }

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
        return [(new WithoutOverlapping('audit-page-'.$this->pageId))->shared()->releaseAfter(15)->expireAfter(250)];
    }

    public function handle(NineRouterClient $client, PromptBuilder $prompt, PageResultValidator $validator, FindingWriter $writer, AuditCoordinator $coordinator): void
    {
        $page = PublicationPage::findOrFail($this->pageId);
        $publication = $page->publication;
        if ($page->status === 'complete' || $page->dpi !== $this->dpi) {
            return;
        }
        $baseline = $this->attemptBaseline ?? max(0, $page->attempts - $this->attempts() + 1);
        if ($page->attempts - $baseline >= 3) {
            $exception = new AiRequestException('Batas percobaan pembacaan halaman tercapai.', 'attempt_limit');
            $this->failed($exception);
            $this->fail($exception);

            return;
        }
        $response = null;
        $requestStarted = false;
        $usageRecorded = false;
        $started = microtime(true);
        try {
            $response = $client->request($publication->aiConfig, $prompt->messages($page, Storage::disk('local')->path($page->image_path)), function () use ($page, &$requestStarted, &$started): void {
                $requestStarted = true;
                $started = microtime(true);
                $page->increment('attempts');
                $page->update(['status' => 'analyzing', 'error' => null]);
            });
            $result = $validator->validate($response['result'], $page->physical_page);
            AiUsageRecord::create(['publication_id' => $publication->id, 'page_id' => $page->id, 'model' => $response['model'], 'request_id' => $response['request_id'], 'usage' => $response['usage'], 'duration_ms' => (int) ((microtime(true) - $started) * 1000), 'status' => $result['readability']]);
            $usageRecorded = true;
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
                // Match export/editor lock order; concurrent AI waits only while saving.
                Publication::whereKey($publication->id)->lockForUpdate()->firstOrFail();
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
            }, 3);
            $coordinator->afterPage($publication);
        } catch (\Throwable $e) {
            if ($e instanceof AiRequestException && $e->category === 'capacity') {
                $this->release($e->retryAfter);

                return;
            }
            if ($requestStarted && ! $usageRecorded) {
                $category = $e instanceof ValidationException ? 'validation' : ($e instanceof AiRequestException ? $e->category : 'internal');
                AiUsageRecord::create(['publication_id' => $publication->id, 'page_id' => $page->id, 'model' => $response['model'] ?? $publication->aiConfig->model, 'request_id' => $response['request_id'] ?? null, 'usage' => $response['usage'] ?? null, 'duration_ms' => (int) ((microtime(true) - $started) * 1000), 'status' => 'failed_'.$category]);
            }
            $formatError = $e instanceof ValidationException || ($e instanceof AiRequestException && $e->category === 'format');
            $formatFailures = $formatError ? AiUsageRecord::where('page_id', $page->id)->where('id', '>', $this->usageBaseline ?? 0)->whereIn('status', ['failed_validation', 'failed_format'])->count() : 0;
            $terminal = ($e instanceof AiRequestException && ! $e->retryable) || $formatFailures >= 2 || $page->attempts - $baseline >= 3;
            if ($terminal) {
                $this->failed($e);
                $this->fail($e);
                if (! $this->job) {
                    throw $e;
                }

                return;
            }
            $page->update(['status' => 'ready', 'error' => $this->failureReason($e).' Proses akan mencoba ulang.']);
            if ($this->job && ($formatError || $e instanceof AiRequestException)) {
                $this->release($formatError ? 5 : ($e instanceof AiRequestException ? $e->retryAfter : 30));

                return;
            }
            throw $e;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $page = PublicationPage::find($this->pageId);
        if (! $page || $page->status === 'complete') {
            return;
        }
        $page->update(['status' => 'failed', 'error' => $this->failureReason($exception).' Pembacaan dihentikan agar tidak terus mengulang. Periksa penyebabnya, coba ulang halaman ini, atau buat Excel sementara.']);
        $page->publication->update(['status' => 'partial_failure']);
    }

    private function failureReason(?\Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            $fields = array_map(fn ($field) => preg_replace('/[^a-zA-Z0-9_.]/', '', (string) $field), array_keys($exception->errors()));

            return 'Format respons AI belum valid pada kolom: '.mb_substr(implode(', ', array_slice($fields, 0, 5)), 0, 250).'.';
        }
        $message = $exception?->getMessage() ?? '';
        $safeMessages = [
            'Koneksi penyedia AI gagal atau melewati batas waktu.',
            'Host penyedia AI tidak dapat dihubungi.',
            'Alamat host penyedia AI tidak diizinkan.',
            'Respons AI terpotong atau dibatasi. Halaman belum dianggap selesai.',
            'Model/rute tidak mengembalikan pembacaan gambar yang valid.',
            'Respons AI bukan JSON lengkap. Jalankan ulang halaman ini.',
            'Struktur respons AI tidak valid.',
        ];
        if (in_array($message, $safeMessages, true) || preg_match('/^Penyedia AI mengembalikan status HTTP \d{3}\. Periksa koneksi, model, atau kuota\.$/', $message)) {
            return $message;
        }

        return 'Pembacaan belum valid atau layanan AI gagal.';
    }
}
