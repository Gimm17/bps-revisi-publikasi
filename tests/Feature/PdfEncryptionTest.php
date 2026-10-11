<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\PdfPreparer;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PdfEncryptionTest extends TestCase
{
    use RefreshDatabase;

    private function encryptedPdf(string $kind): UploadedFile
    {
        $fixtures = json_decode(file_get_contents(base_path('tests/Fixtures/encrypted-pdfs.json')), true, 512, JSON_THROW_ON_ERROR);

        return UploadedFile::fake()->createWithContent('encrypted.pdf', base64_decode($fixtures[$kind], true));
    }

    public function test_permissions_only_pdf_upload_is_accepted_and_renders_without_changing_original(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        $pdf = $this->encryptedPdf('permissions_only');
        $checksum = hash_file('sha256', $pdf->getPathname());
        $this->actingAs(User::factory()->create())->post('/publications', [
            'title' => 'Publikasi dengan pembatasan edit', 'region' => 'Daerah Uji', 'year' => 2026,
            'kind' => 'kcda', 'pdf' => $pdf,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $publication = Publication::firstOrFail();
        $this->assertSame(1, $publication->page_count);
        $this->assertSame(1, $publication->pages()->count());
        $this->assertTrue($publication->manifest['encrypted']);
        $this->assertSame('draft', $publication->status);
        $disk = Storage::disk('local');
        $image = $disk->path('tests/permissions-page.jpg');
        app(PdfPreparer::class)->render($disk->path($publication->pdf_path), 1, $image, 160);
        $this->assertGreaterThan(0, getimagesize($image)[0]);
        $this->assertSame($checksum, hash_file('sha256', $disk->path($publication->pdf_path)));
    }

    public function test_pdf_requiring_open_password_is_rejected_with_clear_message_and_no_record(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create())->post('/publications', [
            'title' => 'Publikasi terkunci', 'region' => 'Daerah Uji', 'year' => 2026,
            'kind' => 'kcda', 'pdf' => $this->encryptedPdf('open_password'),
        ])->assertSessionHasErrors(['pdf' => 'PDF memerlukan password untuk dibuka. Unggah PDF yang dapat dibuka tanpa password.']);
        $this->assertDatabaseCount('publications', 0);
        $this->assertDatabaseCount('publication_pages', 0);
    }
}
