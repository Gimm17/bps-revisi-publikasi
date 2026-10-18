<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\CoverageService;
use App\Audit\WorkbookWriter;
use App\Jobs\WriteWorkbook;
use App\Models\Export;
use App\Models\Finding;
use App\Models\FindingAssessment;
use App\Models\Publication;
use App\Models\PublicationCheck;
use App\Models\PublicationCheckRun;
use App\Models\PublicationReference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\PublicationFixtures;
use Tests\TestCase;

class SupplementalExportTest extends TestCase
{
    use PublicationFixtures, RefreshDatabase;

    private function createPublication(int $pages = 3): Publication
    {
        $this->withoutVite();
        Storage::fake('local');
        Queue::fake()->except(WriteWorkbook::class);

        $user = User::factory()->create();
        $this->actingAs($user)->post('/publications', [
            'title' => 'Kecamatan Ampana Kota Dalam Angka 2026',
            'region' => 'Ampana Kota',
            'year' => 2026,
            'kind' => 'kcda',
            'pdf' => $this->pdfFile($pages),
        ])->assertSessionHasNoErrors();

        $pub = Publication::firstOrFail();

        for ($i = 1; $i <= $pages; $i++) {
            $pub->pages()->where('physical_page', $i)->update([
                'status' => 'complete',
                'result' => $this->pageResult($i),
                'completed_at' => now(),
            ]);
        }

        $pub->update(['status' => 'review_ready']);

        return $pub;
    }

    public function test_reference_evidence_exports_without_fake_target_pages(): void
    {
        $pub = $this->createPublication(3);

        $run = PublicationCheckRun::create([
            'publication_id' => $pub->id,
            'requested_by' => $pub->owner_id,
            'mode' => 'supplemental',
            'baseline_rule_version' => $pub->rule_version,
            'target_rule_version' => 'bps-2026.10.18-v2',
            'prompt_version' => 'page-v2',
            'pdf_sha256' => $pub->pdf_checksum,
            'rules_path' => 'audit-rules/pedoman-v2.md',
            'rules_hash' => hash('sha256', 'v2'),
            'scope' => ['volume'],
            'status' => 'completed',
        ]);

        $reference = PublicationReference::create([
            'check_run_id' => $run->id,
            'series' => 'Kecamatan Ampana Kota Dalam Angka',
            'jurisdiction' => 'Ampana Kota',
            'year' => 2025,
            'source_url' => 'https://sulteng.bps.go.id/publikasi/123.html',
            'provenance' => 'official_browser',
            'access_status' => 'opened',
            'read_status' => 'readable',
            'artifact_manifest' => [
                ['artifact_type' => 'cover_image', 'path' => 'ref/cover.jpg', 'checksum' => hash('sha256', 'cover')],
            ],
        ]);

        $finding = $pub->findings()->create([
            'fingerprint' => hash('sha256', 'vol-test'),
            'rule_code' => 'VOLUME.HISTORY',
            'section' => 'Kover dan Judul',
            'severity' => 'wajib',
            'source' => 'deterministic',
            'observed' => 'Jilid/Volume : 11',
            'expected' => 'Volume 10',
            'recommendation' => 'Sesuaikan nomor volume menjadi 10 mengikuti edisi sebelumnya (Volume 9)',
            'evidences' => [
                [
                    'type' => 'publication_page',
                    'physical_page' => 1,
                    'printed_page' => null,
                    'quote' => 'Jilid/Volume : 11',
                ],
                [
                    'type' => 'reference_artifact',
                    'reference_id' => $reference->id,
                    'artifact_id' => 1,
                    'artifact_type' => 'cover_image',
                    'target_title' => $reference->series.' '.$reference->year,
                    'target_year' => $reference->year,
                    'provenance' => $reference->provenance,
                    'url' => $reference->source_url,
                    'artifact_page' => 1,
                    'quote' => 'Volume 9',
                ],
            ],
            'review_status' => 'unreviewed',
        ]);

        $path = 'test/export_vol.xlsx';
        $report = app(WorkbookWriter::class)->write($pub, $path);

        $this->assertTrue($report['validated']);
        $book = IOFactory::load(Storage::disk('local')->path($path));
        $sheet = $book->getActiveSheet();

        $text = '';
        for ($r = 7; $r <= $report['last_row']; $r++) {
            $text .= ' '.$sheet->getCell('M'.$r)->getValue();
        }

        // Target page must be clearly attributed to PDF page 1
        $this->assertStringContainsString('PDF hlm. 1', $text);
        $this->assertStringContainsString('Jilid/Volume : 11', $text);

        // Reference artifact must NOT be attributed to target publication page
        $this->assertStringNotContainsString('PDF hlm. 1 (tercetak 1) — “Volume 9”', $text);
        $this->assertStringContainsString('Rujukan: Kecamatan Ampana Kota Dalam Angka 2025 (2025) [Unduhan resmi BPS]', $text);
        $this->assertStringContainsString('https://sulteng.bps.go.id/publikasi/123.html', $text);
        $this->assertStringContainsString('Volume 9', $text);
    }

    public function test_excel_preserves_empty_identity_columns_and_extends_border(): void
    {
        $pub = $this->createPublication(3);

        $run = PublicationCheckRun::create([
            'publication_id' => $pub->id,
            'requested_by' => $pub->owner_id,
            'mode' => 'supplemental',
            'baseline_rule_version' => $pub->rule_version,
            'target_rule_version' => 'bps-2026.10.18-v2',
            'prompt_version' => 'page-v2',
            'pdf_sha256' => $pub->pdf_checksum,
            'rules_path' => 'audit-rules/pedoman-v2.md',
            'rules_hash' => hash('sha256', 'v2'),
            'scope' => ['volume'],
            'status' => 'completed',
        ]);

        $check = PublicationCheck::create([
            'check_run_id' => $run->id,
            'check_type' => 'volume',
            'input_hash' => hash('sha256', 'vol-check'),
            'execution_status' => 'completed',
            'decision' => 'needs_verification',
            'error_message' => 'Perlu verifikasi: Volume edisi sebelumnya belum terbaca dari rujukan resmi.',
        ]);

        $finding = $pub->findings()->create([
            'fingerprint' => hash('sha256', 'finding-with-formula'),
            'rule_code' => 'PRINT.ROMAN_END',
            'section' => 'Penomoran',
            'severity' => 'wajib',
            'source' => 'deterministic',
            'observed' => 'Halaman romawi berakhir di ganjil',
            'expected' => 'Halaman romawi harus genap',
            'recommendation' => '=HYPERLINK("http://evil.com", "Klik Disini")',
            'evidences' => [
                ['type' => 'publication_page', 'physical_page' => 2, 'printed_page' => 'ii', 'quote' => 'ii'],
            ],
            'review_status' => 'unreviewed',
        ]);

        $coverage = app(CoverageService::class)->summarize($pub);
        $this->assertSame('complete_with_limitations', $coverage['report_mode']);

        $path = 'test/export_limitations.xlsx';
        $report = app(WorkbookWriter::class)->write($pub, $path, $coverage);

        $book = IOFactory::load(Storage::disk('local')->path($path));
        $sheet = $book->getActiveSheet();
        $lastBodyRow = $report['last_row'];

        $this->assertNull($sheet->getCell('B7')->getValue());
        $this->assertNull($sheet->getCell('K'.$lastBodyRow)->getValue());
        $this->assertSame('thin', $sheet->getStyle('M'.$lastBodyRow)->getBorders()->getBottom()->getBorderStyle());

        // Scan all B7:K(lastBodyRow) are strictly null
        for ($r = 7; $r <= $lastBodyRow; $r++) {
            for ($c = 2; $c <= 11; $c++) {
                $this->assertNull($sheet->getCell([$c, $r])->getValue(), "Cell at col {$c} row {$r} must be null");
            }
        }

        $exportedLimitations = json_encode($sheet->toArray(), JSON_UNESCAPED_UNICODE).$book->getProperties()->getDescription();
        $this->assertStringContainsString('Perlu verifikasi', $exportedLimitations);
    }

    public function test_old_review_requires_explicit_assessment_acceptance(): void
    {
        $pub = $this->createPublication(3);

        $finding = $pub->findings()->create([
            'fingerprint' => hash('sha256', 'finding-reviewed'),
            'rule_code' => 'TITLE.WHITE_BACKGROUND',
            'section' => 'Kover dan Judul',
            'severity' => 'wajib',
            'source' => 'ai_visual',
            'observed' => 'Latar belakang berwarna',
            'expected' => 'Latar belakang putih murni',
            'recommendation' => 'Ubah latar belakang menjadi putih',
            'evidences' => [
                ['type' => 'publication_page', 'physical_page' => 2, 'quote' => 'Judul halaman'],
            ],
            'review_status' => 'accepted',
            'review_note' => 'Disetujui reviewer awal',
        ]);

        // Export v1
        $export1 = $pub->exports()->create([
            'version' => 1,
            'publication_revision' => $pub->revision,
            'created_by' => $pub->owner_id,
        ]);
        (new WriteWorkbook($export1->id))->handle(app(WorkbookWriter::class));
        $export1->refresh();
        $oldExportSha256 = $export1->checksum;
        $this->assertNotNull($oldExportSha256);

        // System runs supplemental check and creates proposed assessment (dismiss)
        $run = PublicationCheckRun::create([
            'publication_id' => $pub->id,
            'requested_by' => $pub->owner_id,
            'mode' => 'supplemental',
            'baseline_rule_version' => $pub->rule_version,
            'target_rule_version' => 'bps-2026.10.18-v2',
            'prompt_version' => 'page-v2',
            'pdf_sha256' => $pub->pdf_checksum,
            'rules_path' => 'audit-rules/pedoman-v2.md',
            'rules_hash' => hash('sha256', 'v2'),
            'scope' => ['volume'],
            'status' => 'completed',
        ]);

        $assessment = FindingAssessment::create([
            'finding_id' => $finding->id,
            'check_run_id' => $run->id,
            'proposed_decision' => 'dismiss',
            'reason' => 'Judul setelah ditinjau ulang memiliki latar belakang putih yang memadai',
            'evidences' => [],
        ]);

        // Prior to explicit reviewer action, finding remains accepted
        $this->assertSame('accepted', $finding->fresh()->review_status);

        // Old export v1 file & checksum are immutable
        $this->assertSame($oldExportSha256, $export1->fresh()->checksum);

        // Reviewer explicitly accepts the assessment
        $reviewer = User::factory()->create();
        $assessment->update([
            'reviewer_id' => $reviewer->id,
            'reviewed_at' => now(),
        ]);
        $finding->update([
            'review_status' => 'rejected',
            'review_note' => 'Usulan sistem diterima: '.$assessment->reason,
            'version' => $finding->version + 1,
        ]);

        $this->assertSame('rejected', $finding->fresh()->review_status);

        // Old export v1 remains completely unchanged
        $this->assertSame($oldExportSha256, $export1->fresh()->checksum);
    }
}
