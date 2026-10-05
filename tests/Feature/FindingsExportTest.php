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
}
