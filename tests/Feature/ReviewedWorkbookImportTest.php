<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\ReviewedWorkbookImporter;
use App\Audit\WorkbookWriter;
use App\Models\ExaminerLearning;
use App\Models\Finding;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\PublicationFixtures;
use Tests\TestCase;
use Illuminate\Support\Facades\Queue;

class ReviewedWorkbookImportTest extends TestCase
{
    use PublicationFixtures, RefreshDatabase;

    private function createTestPublication(User $user): Publication
    {
        $this->withoutVite();
        Storage::fake('local');
        Queue::fake();
        $this->actingAs($user)->post('/publications', [
            'title' => 'Publikasi Uji 2026',
            'region' => 'Uji',
            'year' => 2026,
            'kind' => 'kcda',
            'pdf' => $this->pdfFile(3),
        ])->assertSessionHasNoErrors();

        return Publication::firstOrFail();
    }

    public function test_syncs_deleted_findings_and_creates_learnings(): void
    {
        $user = User::factory()->create();
        $pub = $this->createTestPublication($user);

        $f1 = Finding::create([
            'publication_id' => $pub->id,
            'fingerprint' => 'fp-1',
            'rule_code' => 'RUNNING.TITLE',
            'section' => 'Layout dan running title',
            'severity' => 'verifikasi',
            'review_status' => 'unreviewed',
            'source' => 'cross_check',
            'observed' => 'Running title hilang halaman 25',
            'expected' => 'Ada running title',
            'recommendation' => 'Tambahkan running title',
            'evidences' => [['physical_page' => 25, 'quote' => 'Judul Bab']],
        ]);

        $f2 = Finding::create([
            'publication_id' => $pub->id,
            'fingerprint' => 'fp-2',
            'rule_code' => 'TABLE.SYMBOL',
            'section' => 'Tabel Data',
            'severity' => 'saran',
            'review_status' => 'unreviewed',
            'source' => 'ai_visual',
            'observed' => 'Tanda hubung (-) dipakai untuk nol',
            'expected' => 'Gunakan en dash (–)',
            'recommendation' => 'Ubah tanda hubung',
            'evidences' => [['physical_page' => 31, 'quote' => '-']],
        ]);

        // Generate workbook via WorkbookWriter
        $writer = new WorkbookWriter;
        $excelPath = 'publications/'.$pub->id.'/exports/test-export.xlsx';
        $writer->write($pub, $excelPath);

        $disk = Storage::disk('local');
        $fullPath = $disk->path($excelPath);

        // Load the spreadsheet and delete finding F2 (simulate examiner deleting row in Excel)
        $spreadsheet = IOFactory::load($fullPath);
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestRow();

        // Find row containing #F{$f2->id} and clear it or remove it
        for ($r = 7; $r <= $highestRow; $r++) {
            $val = (string) $sheet->getCell([13, $r])->getValue();
            if (str_contains($val, "#F{$f2->id}")) {
                $sheet->setCellValueExplicit('M'.$r, '', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }

        $modifiedPath = 'publications/'.$pub->id.'/exports/modified-reviewed.xlsx';
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($disk->path($modifiedPath));
        $spreadsheet->disconnectWorksheets();

        // Import reviewed workbook
        $importer = new ReviewedWorkbookImporter;
        $res = $importer->import($disk->path($modifiedPath), $pub, $user);

        $this->assertSame(1, $res['kept']);
        $this->assertSame(1, $res['deleted']);
        $this->assertContains($f2->id, $res['deleted_ids']);

        $f2->refresh();
        $this->assertSame('rejected', $f2->review_status);
        $this->assertStringContainsString('Dihapus dari Excel', $f2->review_note);

        // Verify learning created
        $learning = ExaminerLearning::where('finding_id', $f2->id)->first();
        $this->assertNotNull($learning);
        $this->assertSame('pending', $learning->status);
        $this->assertSame('excel_deletion', $learning->source);
    }
}
