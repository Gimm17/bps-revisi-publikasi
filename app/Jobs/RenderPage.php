<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Audit\PdfPreparer;
use App\Models\PublicationPage;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;

class RenderPage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout = 120;

    public int $uniqueFor = 15000;

    public function __construct(public int $pageId) {}

    public function uniqueId(): string
    {
        return 'render-'.$this->pageId;
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(4);
    }

    public function backoff(): array
    {
        return [15, 45, 90];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('audit-page-'.$this->pageId))->shared()->releaseAfter(10)->expireAfter(250)];
    }

    public function handle(PdfPreparer $pdf): void
    {
        $page = PublicationPage::findOrFail($this->pageId);
        if ($page->status === 'complete') {
            return;
        }
        $disk = Storage::disk('local');
        $pub = $page->publication;
        $path = 'publications/'.$pub->id.'/pages/'.$page->physical_page.'-'.$page->dpi.'.jpg';
        $thumb = 'publications/'.$pub->id.'/pages/'.$page->physical_page.'-thumb.jpg';
        $page->update(['status' => 'rendering', 'error' => null]);
        if (! $disk->exists($path)) {
            $pdf->render($disk->path($pub->pdf_path), $page->physical_page, $disk->path($path), $page->dpi);
        }
        if (! $disk->exists($thumb)) {
            $pdf->render($disk->path($pub->pdf_path), $page->physical_page, $disk->path($thumb), 60);
        }
        $page->update(['image_path' => $path, 'thumbnail_path' => $thumb, 'image_checksum' => hash_file('sha256', $disk->path($path)), 'status' => 'ready']);
        AnalyzePage::dispatch($page->id, $page->dpi)->onQueue('ai');
    }

    public function failed(?\Throwable $exception): void
    {
        $page = PublicationPage::find($this->pageId);
        if (! $page || $page->status === 'complete') {
            return;
        }
        $page->update(['status' => 'failed', 'error' => 'Gambar halaman gagal disiapkan. Jalankan ulang halaman ini.']);
        $page->publication->update(['status' => 'partial_failure']);
    }
}
