<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\AuditCoordinator;
use App\Jobs\FinalizeAudit;
use App\Jobs\WriteWorkbook;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\PublicationFixtures;
use Tests\TestCase;

class PartialExportTest extends TestCase
{
    use PublicationFixtures, RefreshDatabase;

    private function publication(): Publication
    {
        Storage::fake('local');
        Queue::fake()->except(WriteWorkbook::class);
        Http::preventStrayRequests();
        $this->withoutVite();
        $this->actingAs(User::factory()->create())->post('/publications', [
            'title' => 'Publikasi Uji 2026', 'region' => 'Uji', 'year' => 2026,
            'kind' => 'kcda', 'pdf' => $this->pdfFile(3),
        ])->assertSessionHasNoErrors();
        $pub = Publication::firstOrFail();
        $pub->update(['status' => 'partial_failure']);
        $pub->pages()->where('physical_page', '<', 3)->update([
            'status' => 'complete', 'result' => json_encode($this->pageResult()), 'completed_at' => now(),
        ]);
        $pub->pages()->where('physical_page', 3)->update(['status' => 'failed', 'error' => 'Respons AI belum valid.']);
        $pub->findings()->create([
            'fingerprint' => 'preserved-finding', 'rule_code' => 'MANUAL.REVIEW', 'section' => 'Kover',
            'severity' => 'verifikasi', 'source' => 'manual', 'confidence' => 1,
            'observed' => 'Temuan yang sudah tersimpan', 'expected' => 'Hasil dipertahankan',
            'recommendation' => 'Periksa catatan ini', 'evidences' => [['physical_page' => 1, 'quote' => 'PUBLIKASI TEST']],
        ]);

        return $pub;
    }

    public function test_confirmed_partial_export_is_immediate_and_preserves_existing_results(): void
    {
        $pub = $this->publication();
        $before = $pub->pages()->get()->toArray();
        $findings = $pub->findings()->get()->toArray();
        $this->post('/publications/'.$pub->id.'/exports/partial', ['confirm' => true])->assertSessionHasNoErrors()->assertRedirect();
        $export = $pub->exports()->firstOrFail();
        $this->assertSame('complete', $export->status);
        $this->assertSame('partial', $export->validation['mode']);
        $this->assertSame(2, $export->validation['checked_pages']);
        $this->assertSame([3], array_column($export->validation['unchecked_pages'], 'physical_page'));
        $this->assertSame('partial_failure', $pub->fresh()->status);
        $this->assertSame($before, $pub->pages()->get()->toArray());
        $this->assertSame($findings, $pub->findings()->get()->toArray());
        $book = IOFactory::load(Storage::disk('local')->path($export->path));
        $text = json_encode($book->getActiveSheet()->toArray(), JSON_UNESCAPED_UNICODE);
        foreach (['BELUM LENGKAP', '2 dari 3', 'PDF hlm. 3', 'Temuan yang sudah tersimpan'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
        $this->assertStringNotContainsString('seluruh 3 halaman diperiksa', $book->getProperties()->getDescription());
        $book->disconnectWorksheets();
        $this->get('/publications/'.$pub->id.'/exports/'.$export->id)->assertDownload('Publikasi Uji 2026 - SEMENTARA BELUM LENGKAP v1.xlsx');
        Http::assertNothingSent();
    }

    public function test_partial_export_requires_confirmation_and_authorization(): void
    {
        $pub = $this->publication();
        $this->post('/publications/'.$pub->id.'/exports/partial', [])->assertSessionHasErrors('confirm');
        $this->actingAs(User::factory()->create())->post('/publications/'.$pub->id.'/exports/partial', ['confirm' => true])->assertForbidden();
        $this->assertDatabaseCount('exports', 0);
    }

    public function test_partial_export_cannot_claim_a_complete_flag_without_a_result(): void
    {
        $pub = $this->publication();
        $pub->pages()->update(['status' => 'complete', 'result' => null]);
        $this->post('/publications/'.$pub->id.'/exports/partial', ['confirm' => true])->assertSessionHasErrors('export');
        $this->assertDatabaseCount('exports', 0);
    }

    public function test_full_auto_export_is_not_suppressed_by_an_earlier_partial_export(): void
    {
        $pub = $this->publication();
        $this->post('/publications/'.$pub->id.'/exports/partial', ['confirm' => true])->assertSessionHasNoErrors();
        foreach ($pub->pages as $page) {
            $page->update(['status' => 'complete', 'result' => $this->pageResult($page->physical_page), 'completed_at' => now()]);
        }
        app()->call([new FinalizeAudit($pub->id), 'handle']);
        $this->assertSame(2, $pub->exports()->count());
        $full = $pub->exports()->firstOrFail();
        app()->call([new WriteWorkbook($full->id), 'handle']);
        $this->assertSame('full', $full->fresh()->validation['mode']);
        $this->assertSame('review_ready', $pub->fresh()->status);
        $this->assertTrue(app(AuditCoordinator::class)->canComplete($pub));
    }

    public function test_partial_export_cannot_be_used_to_lock_final_results(): void
    {
        $pub = $this->publication();
        $this->post('/publications/'.$pub->id.'/exports/partial', ['confirm' => true]);
        $pub->findings()->update(['review_status' => 'accepted']);
        foreach ($pub->pages as $page) {
            $page->update(['status' => 'complete', 'result' => $this->pageResult($page->physical_page), 'completed_at' => now()]);
        }
        $pub->update(['status' => 'review_ready']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/publications/'.$pub->id.'/lock')->assertSessionHasErrors('lock');
        $this->assertNull($pub->fresh()->locked_at);
    }

    public function test_partial_export_does_not_pass_the_evaluation_release_gate(): void
    {
        $pub = $this->publication();
        foreach ($pub->pages as $page) {
            $page->update(['status' => 'complete', 'result' => $this->pageResult($page->physical_page), 'completed_at' => now()]);
        }
        $finding = $pub->findings()->firstOrFail();
        $finding->update(['source' => 'ai_visual', 'severity' => 'wajib']);
        $this->post('/publications/'.$pub->id.'/exports/partial', ['confirm' => true])->assertSessionHasNoErrors();
        Storage::disk('local')->put('gold.json', json_encode([
            'publication_id' => $pub->id, 'pdf_sha256' => $pub->pdf_checksum, 'adjudicated' => true,
            'expected' => [['id' => 'a', 'severity' => 'wajib']], 'matches' => [['gold_id' => 'a', 'finding_id' => $finding->id]],
        ]));
        $exit = Artisan::call('audit:evaluate', ['gold' => Storage::disk('local')->path('gold.json')]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $report['validated_exports']);
        $this->assertFalse($report['release_gate_passed']);
        $this->assertSame(1, $exit);
    }
}
