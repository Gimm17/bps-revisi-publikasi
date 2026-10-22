<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\WorkbookWriter;
use App\Models\Finding;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\PublicationFixtures;
use Tests\TestCase;

class FindingsExportTest extends TestCase
{
    use PublicationFixtures, RefreshDatabase;

    private function publication(): Publication
    {
        $this->withoutVite();
        Storage::fake('local');
        Queue::fake();
        $this->actingAs(User::factory()->create())->post('/publications', ['title' => 'Publikasi Uji 2026', 'region' => 'Uji', 'year' => 2026, 'kind' => 'kcda', 'pdf' => $this->pdfFile(3)])->assertSessionHasNoErrors();

        return Publication::firstOrFail();
    }

    private function finding(Publication $p): Finding
    {
        return $p->findings()->create(['fingerprint' => uniqid(), 'rule_code' => 'ISSN.COLON', 'section' => 'Kover', 'severity' => 'wajib', 'source' => 'ai', 'confidence' => 0.9, 'observed' => 'ISSN: 1234-5678', 'expected' => 'Tanpa titik dua', 'recommendation' => 'Hapus titik dua', 'evidences' => [['physical_page' => 1, 'quote' => 'ISSN: 1234-5678', 'bbox' => null]]]);
    }

    public function test_edits_record_history_and_reject_stale_version(): void
    {
        $p = $this->publication();
        $f = $this->finding($p);
        $data = ['version' => 1, 'rule_code' => $f->rule_code, 'severity' => 'wajib', 'observed' => $f->observed, 'expected' => $f->expected, 'recommendation' => 'Perbaikan diperjelas', 'review_status' => 'accepted', 'review_note' => 'Sudah diperiksa', 'quote' => ''];
        $this->patch('/publications/'.$p->id.'/findings/'.$f->id, $data)->assertSessionHasNoErrors();
        $this->assertSame(2, $f->fresh()->version);
        $this->assertSame(1, $f->revisions()->count());
        $this->patchJson('/publications/'.$p->id.'/findings/'.$f->id, $data)->assertConflict();
    }

    public function test_rejected_finding_with_reason_records_permanent_examiner_learning(): void
    {
        $p = $this->publication();
        $f = $this->finding($p);
        $data = [
            'version' => 1,
            'rule_code' => $f->rule_code,
            'severity' => 'wajib',
            'observed' => $f->observed,
            'expected' => $f->expected,
            'recommendation' => $f->recommendation,
            'review_status' => 'rejected',
            'review_note' => 'INI TIDAK ADA DI ATURAN PEDOMAN SOAL LOGO',
            'evidences' => $f->evidences,
        ];
        $this->patch('/publications/'.$p->id.'/findings/'.$f->id, $data)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame('rejected', $f->fresh()->review_status);
        $this->assertSame('INI TIDAK ADA DI ATURAN PEDOMAN SOAL LOGO', $f->fresh()->review_note);

        $learningService = app(\App\Audit\ExaminerLearningService::class);
        $promptText = $learningService->activeLearningsForPrompt();
        $this->assertStringContainsString('PEMBELAJARAN PERMANEN', $promptText);
        $this->assertStringContainsString('TIDAK ADA DI ATURAN PEDOMAN SOAL LOGO', $promptText);
    }

    public function test_locked_publication_is_not_editable(): void
    {
        $p = $this->publication();
        $f = $this->finding($p);
        $p->update(['locked_at' => now()]);
        $this->patch('/publications/'.$p->id.'/findings/'.$f->id, [])->assertForbidden();
    }

    public function test_excel_clears_template_values_extends_borders_and_preserves_evidence(): void
    {
        $p = $this->publication();
        $this->finding($p);
        for ($i = 0; $i < 150; $i++) {
            $this->finding($p);
        }
        $p->findings()->first()->update(['recommendation' => '=HYPERLINK("evil")']);
        $path = 'test/export.xlsx';
        $report = app(WorkbookWriter::class)->write($p, $path);
        $this->assertSame(151, $report['finding_count']);
        $this->assertTrue($report['validated']);
        $b = IOFactory::load(Storage::disk('local')->path($path));
        $s = $b->getActiveSheet();
        for ($r = 7; $r <= $report['last_row']; $r++) {
            for ($c = 2; $c <= 11; $c++) {
                $this->assertNull($s->getCell([$c, $r])->getValue());
            }
        }
        $this->assertSame('thin', $s->getStyle('M'.$report['last_row'])->getBorders()->getBottom()->getBorderStyle());
        $this->assertSame('s', $s->getCell('M8')->getDataType());
        $this->assertStringContainsString('PDF hlm. 1', $s->getCell('M8')->getValue());
    }

    public function test_sections_are_ordered_from_front_cover_to_back_cover(): void
    {
        $p = $this->publication();
        $p->findings()->delete();

        // Insert in reverse order to ensure sorting works regardless of insert order
        $p->findings()->create([
            'fingerprint' => 'back-1',
            'rule_code' => 'BACK.BARCODE',
            'section' => 'back_cover',
            'severity' => 'wajib',
            'source' => 'ai_visual',
            'confidence' => 0.95,
            'observed' => 'Barcode hilang',
            'expected' => 'Ada barcode',
            'recommendation' => 'Tambahkan barcode',
            'evidences' => [['physical_page' => 103, 'quote' => 'back', 'bbox' => null]],
        ]);
        $p->findings()->create([
            'fingerprint' => 'table-1',
            'rule_code' => 'TABLE.TITLE',
            'section' => 'table',
            'severity' => 'wajib',
            'source' => 'ai_visual',
            'confidence' => 0.95,
            'observed' => 'Judul tabel salah',
            'expected' => 'Judul tabel benar',
            'recommendation' => 'Perbaiki judul',
            'evidences' => [['physical_page' => 25, 'quote' => 'tabel', 'bbox' => null]],
        ]);
        $p->findings()->create([
            'fingerprint' => 'front-1',
            'rule_code' => 'COVER.TITLE',
            'section' => 'front_cover',
            'severity' => 'wajib',
            'source' => 'ai_visual',
            'confidence' => 0.95,
            'observed' => 'Judul kover salah',
            'expected' => 'Judul kover benar',
            'recommendation' => 'Perbaiki judul kover',
            'evidences' => [['physical_page' => 1, 'quote' => 'kover', 'bbox' => null]],
        ]);

        $path = 'test/export_order.xlsx';
        $report = app(WorkbookWriter::class)->write($p, $path);
        $this->assertSame(3, $report['finding_count']);

        $b = IOFactory::load(Storage::disk('local')->path($path));
        $s = $b->getActiveSheet();

        // Collect all section header texts in column L
        $sectionHeaders = [];
        for ($r = 7; $r <= $report['last_row']; $r++) {
            $val = (string) $s->getCell('L'.$r)->getValue();
            if ($val !== '' && ! in_array($val, ['•', '↳', 'Cakupan pemeriksaan', 'Keterbatasan pemeriksaan'], true)) {
                $sectionHeaders[] = $val;
            }
        }

        // Section headers must follow BPS sequence: Kover depan first, then Tabel Data, and Kover belakang last!
        $this->assertSame(['Kover depan', 'Tabel Data', 'Kover belakang'], $sectionHeaders);
    }

    public function test_rejected_finding_is_included_in_excel_with_examiner_note_and_no_unreviewed_status(): void
    {
        $p = $this->publication();
        $p->findings()->delete();

        $f1 = $p->findings()->create([
            'fingerprint' => 'unreviewed-1',
            'rule_code' => 'LANGUAGE.BILINGUAL',
            'section' => 'front_cover',
            'severity' => 'wajib',
            'source' => 'ai_visual',
            'confidence' => 0.95,
            'observed' => 'Judul tunggal Figure',
            'expected' => 'Judul jamak Figures',
            'recommendation' => 'Ubah Figure jadi Figures',
            'review_status' => 'unreviewed',
            'evidences' => [['physical_page' => 1, 'quote' => 'Figure', 'bbox' => null]],
        ]);

        $f2 = $p->findings()->create([
            'fingerprint' => 'rejected-1',
            'rule_code' => 'TITLE.COVER_LAYOUT',
            'section' => 'title',
            'severity' => 'wajib',
            'source' => 'ai_visual',
            'confidence' => 0.95,
            'observed' => 'Logo BPS di kanan bawah bukan kiri atas',
            'expected' => 'Logo di kiri atas',
            'recommendation' => 'Pindahkan logo',
            'review_status' => 'rejected',
            'review_note' => 'Bukan pelanggaran pedoman BPS 2.1 butir 8',
            'evidences' => [['physical_page' => 3, 'quote' => 'Logo', 'bbox' => null]],
        ]);

        $path = 'test/export_rejected.xlsx';
        $report = app(WorkbookWriter::class)->write($p, $path);
        $this->assertSame(2, $report['finding_count']);

        $b = IOFactory::load(Storage::disk('local')->path($path));
        $s = $b->getActiveSheet();

        $allMText = '';
        for ($r = 7; $r <= $report['last_row']; $r++) {
            $allMText .= ' '.(string) $s->getCell('M'.$r)->getValue();
        }

        // Must NOT contain noisy "Status: unreviewed"
        $this->assertStringNotContainsString('Status: unreviewed', $allMText);
        $this->assertStringNotContainsString('Status: rejected', $allMText);

        // Must contain rejection tag and rejection note for the rejected finding
        $this->assertStringContainsString('[DITOLAK · WAJIB · TITLE.COVER_LAYOUT]', $allMText);
        $this->assertStringContainsString('Catatan Penolakan Pemeriksa: Bukan pelanggaran pedoman BPS 2.1 butir 8', $allMText);

        // Regular finding is formatted cleanly
        $this->assertStringContainsString('[WAJIB · LANGUAGE.BILINGUAL]', $allMText);
    }

    public function test_smart_chapter_detection_and_running_title_consolidation_in_excel(): void
    {
        $p = $this->publication();
        $p->findings()->delete();

        // 1. Front Cover
        $p->findings()->create([
            'fingerprint' => 'front-test',
            'rule_code' => 'COVER.COMPONENTS',
            'section' => 'front_cover',
            'severity' => 'wajib',
            'source' => 'ai_visual',
            'confidence' => 0.95,
            'observed' => 'Judul kover perlu disesuaikan',
            'expected' => 'Judul kover baku',
            'recommendation' => 'Gunakan font standar',
            'evidences' => [['physical_page' => 1, 'quote' => 'kover', 'bbox' => null]],
        ]);

        // 2. Bab 1 Geografi
        $p->findings()->create([
            'fingerprint' => 'bab1-test',
            'rule_code' => 'TABLE.FORMAT',
            'section' => 'table',
            'severity' => 'saran',
            'source' => 'ai_visual',
            'confidence' => 0.90,
            'observed' => 'Format Tabel 1.1 Luas Wilayah Geografi desimal tidak konsisten',
            'expected' => 'Dua desimal seragam',
            'recommendation' => 'Samakan desimal',
            'evidences' => [['physical_page' => 15, 'quote' => 'Tabel 1.1', 'bbox' => null]],
        ]);

        // 3. Bab 2 Pemerintahan
        $p->findings()->create([
            'fingerprint' => 'bab2-test',
            'rule_code' => 'TABLE.TOTAL',
            'section' => 'narrative',
            'severity' => 'verifikasi',
            'source' => 'ai_visual',
            'confidence' => 0.90,
            'observed' => 'Jumlah SLS pada ulasan pemerintahan desa tercatat 72 tetapi komponen 81',
            'expected' => 'Konsisten 81',
            'recommendation' => 'Verifikasi SLS desa',
            'evidences' => [['physical_page' => 22, 'quote' => 'SLS 72', 'bbox' => null]],
        ]);

        // 4. Repetitive Running Titles across 4 pages
        foreach ([11, 17, 19, 21] as $page) {
            $p->findings()->create([
                'fingerprint' => 'rt-'.$page,
                'rule_code' => 'RUNNING.TITLE',
                'section' => 'narrative',
                'severity' => 'wajib',
                'source' => 'ai_visual',
                'confidence' => 0.95,
                'observed' => 'Halaman bernomor Arab ganjil '.$page.' tidak memiliki running title pada bagian atas halaman.',
                'expected' => 'Halaman ganjil memuat running title judul bab.',
                'recommendation' => 'Tambahkan running title.',
                'evidences' => [['physical_page' => $page, 'quote' => 'Header kosong', 'bbox' => null]],
            ]);
        }

        // 5. Back Cover
        $p->findings()->create([
            'fingerprint' => 'back-test',
            'rule_code' => 'BACK.BARCODE',
            'section' => 'back_cover',
            'severity' => 'wajib',
            'source' => 'ai_visual',
            'confidence' => 0.95,
            'observed' => 'Barcode hilang pada kover belakang',
            'expected' => 'Ada barcode ISSN',
            'recommendation' => 'Tambahkan barcode',
            'evidences' => [['physical_page' => 100, 'quote' => 'Kover belakang', 'bbox' => null]],
        ]);

        $path = 'test/export_smart_chapters.xlsx';
        $report = app(WorkbookWriter::class)->write($p, $path);

        // 8 original findings should be consolidated: 4 running titles become 1 -> 5 total findings in report!
        $this->assertSame(5, $report['finding_count']);

        $b = IOFactory::load(Storage::disk('local')->path($path));
        $s = $b->getActiveSheet();

        $sectionHeaders = [];
        $allMText = '';
        for ($r = 7; $r <= $report['last_row']; $r++) {
            $val = (string) $s->getCell('L'.$r)->getValue();
            if ($val !== '' && ! in_array($val, ['•', '↳', 'Cakupan pemeriksaan', 'Keterbatasan pemeriksaan'], true)) {
                $sectionHeaders[] = $val;
            }
            $allMText .= ' '.(string) $s->getCell('M'.$r)->getValue();
        }

        // Section sequence must be intelligent and follow BPS publication order:
        // Kover depan -> Layout dan running title -> Bab 1 Geografi -> Bab 2 Pemerintahan -> Kover belakang
        $this->assertSame([
            'Kover depan',
            'Layout dan running title',
            'Bab 1 Geografi',
            'Bab 2 Pemerintahan',
            'Kover belakang',
        ], $sectionHeaders);

        // Consolidated running title must list all 4 pages cleanly
        $this->assertStringContainsString('Running title tidak tercantum pada 4 halaman ganjil bernomor Arab', $allMText);
        $this->assertStringContainsString('PDF hlm. 11, 17, 19, 21', $allMText);
    }
}
