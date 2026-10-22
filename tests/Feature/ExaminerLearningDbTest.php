<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\ExaminerLearningService;
use App\Audit\LearningSuppressor;
use App\Models\Finding;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExaminerLearningDbTest extends TestCase
{
    use RefreshDatabase;

    protected function createPublication(User $user): Publication
    {
        return Publication::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'owner_id' => $user->id,
            'title' => 'Publikasi Uji 2026',
            'region' => 'Uji',
            'year' => 2026,
            'kind' => 'kcda',
            'pdf_path' => 'publications/test.pdf',
            'pdf_name' => 'test.pdf',
            'pdf_checksum' => hash('sha256', 'test'),
            'template_path' => 'templates/test.xlsx',
            'template_name' => 'test.xlsx',
            'template_checksum' => hash('sha256', 'template'),
            'page_count' => 10,
            'rule_version' => 'bps-2026.10.18-v2',
            'manifest' => [],
        ]);
    }

    public function test_seeds_defaults_and_records_rejection_learning(): void
    {
        $service = new ExaminerLearningService;
        $all = $service->all();

        $this->assertNotEmpty($all);
        $this->assertTrue($all->contains('key', 'learning-logo-position'));

        $user = User::factory()->create();
        $pub = $this->createPublication($user);
        $finding = Finding::create([
            'publication_id' => $pub->id,
            'fingerprint' => 'test-fp-1',
            'rule_code' => 'TITLE.COVER_LAYOUT',
            'section' => 'Kover depan',
            'severity' => 'wajib',
            'source' => 'ai_visual',
            'observed' => 'Logo BPS berada di sebelah kanan bawah.',
            'expected' => 'Logo BPS di kiri atas.',
            'recommendation' => 'Pindahkan logo.',
            'evidences' => [['physical_page' => 1, 'quote' => 'Logo BPS']],
        ]);

        $learning = $service->recordRejection($finding, 'Pedoman BPS memperbolehkan logo di kanan bawah', $user);

        $this->assertSame('TITLE.COVER_LAYOUT', $learning->rule_code);
        $this->assertSame('active', $learning->status);
        $this->assertSame('false_positive', $learning->kind);
        $this->assertStringContainsString('DILARANG', $learning->instruction);

        $promptText = $service->activeLearningsForPrompt(null, ['TITLE.COVER_LAYOUT']);
        $this->assertStringContainsString('TITLE.COVER_LAYOUT', $promptText);
    }

    public function test_learning_suppressor_suppresses_learned_finding_and_increments_hits(): void
    {
        $service = new ExaminerLearningService;
        $user = User::factory()->create();
        $pub = $this->createPublication($user);
        $finding = Finding::create([
            'publication_id' => $pub->id,
            'fingerprint' => 'test-fp-2',
            'rule_code' => 'TITLE.COVER_LAYOUT',
            'section' => 'Kover depan',
            'severity' => 'wajib',
            'source' => 'ai_visual',
            'observed' => 'Tanda tangan kepala BPS berada di sebelah kanan bukan kiri.',
            'expected' => 'Tanda tangan di sebelah kiri.',
            'recommendation' => 'Pindahkan tanda tangan.',
            'evidences' => [['physical_page' => 1, 'quote' => 'Tanda tangan']],
        ]);

        $learning = $service->recordRejection($finding, 'Pedoman BPS memperbolehkan tanda tangan di kanan', $user);
        $this->assertSame(0, $learning->hits);

        $suppressor = new LearningSuppressor;
        $candidate = [
            'rule_code' => 'TITLE.COVER_LAYOUT',
            'observed' => 'Tanda tangan kepala BPS berada di sebelah kanan bukan kiri.',
        ];

        $res = $suppressor->evaluate($candidate);
        $this->assertTrue($res['suppress']);
        $this->assertSame($learning->id, $res['learning_id']);

        $learning->refresh();
        $this->assertSame(1, $learning->hits);
    }
}
