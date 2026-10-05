<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\WorkbookWriter;
use App\Jobs\RenderPage;
use App\Models\AiProviderConfig;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\PublicationFixtures;
use Tests\TestCase;

class FinalReviewTest extends TestCase
{
    use PublicationFixtures, RefreshDatabase;

    private function publication(): Publication
    {
        $this->withoutVite();
        Storage::fake('local');
        Queue::fake();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/publications', ['title' => 'Publikasi Review', 'region' => 'Test', 'year' => 2026, 'kind' => 'kcda', 'pdf' => $this->pdfFile(3)])->assertSessionHasNoErrors();

        return Publication::firstOrFail();
    }

    public function test_unlock_cannot_bypass_completion_and_only_reopens_a_valid_final(): void
    {
        $p = $this->publication();
        foreach (['draft', 'partial_failure', 'review_ready'] as $status) {
            $p->update(['status' => $status]);
            $this->post('/publications/'.$p->id.'/unlock')->assertSessionHasErrors('unlock');
            $this->assertSame($status, $p->fresh()->status);
        }
        $p->update(['status' => 'final', 'locked_at' => now()]);
        $this->post('/publications/'.$p->id.'/unlock')->assertSessionHasErrors('unlock');
        $p->pages()->update(['status' => 'complete', 'result' => json_encode($this->pageResult()), 'completed_at' => now()]);
        $this->post('/publications/'.$p->id.'/unlock')->assertSessionHasNoErrors();
        $this->assertSame('review_ready', $p->fresh()->status);
        $this->assertNull($p->fresh()->locked_at);
    }

    public function test_explicit_provider_migration_preserves_completed_pages_and_records_the_change(): void
    {
        $p = $this->publication();
        $old = AiProviderConfig::create(['base_url' => 'https://9router.com/v1', 'api_key' => 'old', 'model' => 'vision', 'vision_tested_at' => now(), 'created_by' => auth()->id()]);
        $new = AiProviderConfig::create(['base_url' => 'https://9router.com/v1', 'api_key' => 'new', 'model' => 'vision-new', 'vision_tested_at' => now(), 'created_by' => auth()->id()]);
        $p->update(['ai_config_id' => $old->id, 'status' => 'partial_failure']);
        $p->pages()->where('physical_page', 1)->update(['status' => 'complete', 'result' => json_encode($this->pageResult()), 'completed_at' => now()]);
        $this->post('/publications/'.$p->id.'/rebind-ai', ['confirm' => false])->assertSessionHasErrors('confirm');
        $p->pages()->where('physical_page', 2)->update(['status' => 'analyzing']);
        $this->post('/publications/'.$p->id.'/rebind-ai', ['confirm' => true])->assertSessionHasErrors('ai');
        $this->assertSame($old->id, $p->fresh()->ai_config_id);
        $p->pages()->where('physical_page', 2)->update(['status' => 'failed']);
        $this->post('/publications/'.$p->id.'/rebind-ai', ['confirm' => true])->assertSessionHasNoErrors();
        $this->assertSame($new->id, $p->fresh()->ai_config_id);
        $this->assertSame('complete', $p->pages()->where('physical_page', 1)->first()->status);
        Queue::assertPushed(RenderPage::class, 2);
        $this->assertDatabaseHas('audit_events', ['action' => 'audit.provider_rebound', 'publication_id' => $p->id]);
    }

    public function test_evidence_correction_is_saved_with_history_and_page_validation(): void
    {
        $p = $this->publication();
        $f = $p->findings()->create(['fingerprint' => 'evidence-test', 'rule_code' => 'MANUAL.REVIEW', 'section' => 'Manual', 'severity' => 'verifikasi', 'source' => 'manual', 'confidence' => 1, 'observed' => 'Kondisi', 'expected' => 'Aturan', 'recommendation' => 'Perbaiki', 'evidences' => [['physical_page' => 1, 'quote' => 'Salah halaman', 'bbox' => null]]]);
        $data = ['version' => 1, 'rule_code' => $f->rule_code, 'severity' => 'verifikasi', 'observed' => $f->observed, 'expected' => $f->expected, 'recommendation' => $f->recommendation, 'review_status' => 'accepted', 'evidences' => [['physical_page' => 2, 'quote' => 'Bukti yang benar', 'bbox' => null]]];
        $this->patch('/publications/'.$p->id.'/findings/'.$f->id, $data)->assertSessionHasNoErrors();
        $this->assertSame(2, $f->fresh()->evidences[0]['physical_page']);
        $this->assertSame('Bukti yang benar', $f->fresh()->evidences[0]['quote']);
        $this->assertSame(1, $f->revisions()->first()->before['evidences'][0]['physical_page']);
        $data['version'] = 2;
        $data['evidences'][0]['physical_page'] = 4;
        $this->patch('/publications/'.$p->id.'/findings/'.$f->id, $data)->assertSessionHasErrors('evidences.0.physical_page');
        unset($data['evidences']);
        $data['page'] = 3;
        $data['quote'] = 'Koreksi lewat format lama';
        $this->patch('/publications/'.$p->id.'/findings/'.$f->id, $data)->assertSessionHasNoErrors();
        $this->assertSame(3, $f->fresh()->evidences[0]['physical_page']);
        $this->assertSame('Koreksi lewat format lama', $f->fresh()->evidences[0]['quote']);
    }

    public function test_newline_heavy_excel_content_is_split_without_clipping_or_lost_text(): void
    {
        $p = $this->publication();
        $observed = implode("\n", array_fill(0, 100, 'Baris singkat'));
        $p->findings()->create(['fingerprint' => 'newline-test', 'rule_code' => 'MANUAL.REVIEW', 'section' => 'Manual', 'severity' => 'verifikasi', 'source' => 'manual', 'confidence' => 1, 'observed' => $observed, 'expected' => 'Aturan', 'recommendation' => 'Perbaiki', 'evidences' => [['physical_page' => 1, 'quote' => 'Bukti', 'bbox' => null]]]);
        $report = app(WorkbookWriter::class)->write($p, 'test/newlines.xlsx');
        $sheet = IOFactory::load(Storage::disk('local')->path('test/newlines.xlsx'))->getActiveSheet();
        $all = '';
        for ($r = 8; $r <= $report['last_row']; $r++) {
            $text = (string) $sheet->getCell('M'.$r)->getValue();
            $lines = array_sum(array_map(fn ($line) => max(1, (int) ceil(mb_strlen($line) / 95)), explode("\n", $text)));
            $this->assertLessThanOrEqual(28, $lines, 'A row must fit within Excel maximum height');
            $this->assertGreaterThanOrEqual($lines * 14 + 12, $sheet->getRowDimension($r)->getRowHeight());
            $all .= $text;
        }
        $this->assertStringContainsString($observed, $all);
    }
}
