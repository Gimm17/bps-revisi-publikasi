<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Audit\AuditMode;
use App\Audit\FusionLearningService;
use App\Audit\FusionReconciler;
use App\Audit\PageTypeClassifier;
use App\Models\ExaminerLearning;
use App\Models\FusionCandidate;
use App\Models\Publication;
use App\Models\PublicationPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThreeAuditModesTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_mode_enum_helpers(): void
    {
        $ai = AuditMode::Ai;
        $this->assertTrue($ai->needsAi());
        $this->assertFalse($ai->needsMachine());

        $machine = AuditMode::Machine;
        $this->assertFalse($machine->needsAi());
        $this->assertTrue($machine->needsMachine());

        $fusion = AuditMode::Fusion;
        $this->assertTrue($fusion->needsAi());
        $this->assertTrue($fusion->needsMachine());
    }

    public function test_page_type_classifier(): void
    {
        $classifier = new PageTypeClassifier();

        // Cover
        $p1 = new PublicationPage(['physical_page' => 1, 'text_layer' => 'KECAMATAN AMPANA KOTA DALAM ANGKA 2026']);
        $this->assertSame('front_cover', $classifier->classify($p1, 50));

        // Back cover
        $pEnd = new PublicationPage(['physical_page' => 50, 'text_layer' => 'Sensus Pertanian 2023']);
        $this->assertSame('back_cover', $classifier->classify($pEnd, 50));

        // Blank
        $pBlank = new PublicationPage(['physical_page' => 2, 'text_layer' => '   ']);
        $this->assertSame('blank', $classifier->classify($pBlank, 50));

        // Catalogue
        $pCat = new PublicationPage(['physical_page' => 4, 'text_layer' => "KATALOG BPS: 1102001.7209\nNo. Publikasi: 1102001.2605"]);
        $this->assertSame('catalogue', $classifier->classify($pCat, 50));

        // Team
        $pTeam = new PublicationPage(['physical_page' => 5, 'text_layer' => "TIM PENYUSUN\nEditor: BPS"]);
        $this->assertSame('team', $classifier->classify($pTeam, 50));

        // Preface
        $pPref = new PublicationPage(['physical_page' => 6, 'text_layer' => "KATA PENGANTAR\nPuji syukur kita panjatkan..."]);
        $this->assertSame('preface', $classifier->classify($pPref, 50));

        // Contents
        $pToc = new PublicationPage(['physical_page' => 7, 'text_layer' => "DAFTAR ISI\n1.1 Keadaan Geografis ... 3"]);
        $this->assertSame('contents', $classifier->classify($pToc, 50));

        // Table
        $pTbl = new PublicationPage(['physical_page' => 15, 'text_layer' => "Tabel 1.1 Luas Wilayah Menurut Desa, 2025"]);
        $this->assertSame('table', $classifier->classify($pTbl, 50));
    }

    public function test_fusion_reconciler_groups_agreed_and_discrepant_findings(): void
    {
        $user = User::factory()->create();
        $pub = Publication::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'owner_id' => $user->id,
            'title' => 'Test Publikasi',
            'region' => 'Ampana',
            'year' => 2026,
            'kind' => 'kcda',
            'audit_mode' => 'fusion',
            'pdf_path' => 'dummy.pdf',
            'pdf_name' => 'dummy.pdf',
            'pdf_checksum' => 'abc',
            'template_path' => 'dummy.xlsx',
            'template_name' => 'dummy.xlsx',
            'template_checksum' => 'def',
            'page_count' => 10,
            'manifest' => [],
            'rule_version' => 'bps-2026.10.18-v2',
        ]);

        $page = $pub->pages()->create(['physical_page' => 4]);

        // Machine candidate 1 (ISSN colon)
        $m1 = FusionCandidate::create([
            'publication_id' => $pub->id,
            'publication_page_id' => $page->id,
            'physical_page' => 4,
            'engine' => 'machine',
            'rule_code' => 'CATALOGUE.ISSN_COLON_RULE',
            'severity' => 'wajib',
            'observed' => 'ISSN 2655-108X',
            'expected' => 'ISSN: 2655-108X',
            'recommendation' => 'Gunakan titik dua',
        ]);

        // AI candidate 1 (matches m1 via alias ISSN.COLON)
        $a1 = FusionCandidate::create([
            'publication_id' => $pub->id,
            'publication_page_id' => $page->id,
            'physical_page' => 4,
            'engine' => 'ai',
            'rule_code' => 'ISSN.COLON',
            'severity' => 'wajib',
            'observed' => 'ISSN 2655-108X',
            'expected' => 'ISSN: 2655-108X',
            'recommendation' => 'Gunakan titik dua rapat',
        ]);

        // Machine candidate 2 (discrepancy: machine only)
        $m2 = FusionCandidate::create([
            'publication_id' => $pub->id,
            'publication_page_id' => $page->id,
            'physical_page' => 4,
            'engine' => 'machine',
            'rule_code' => 'DOCUMENT.RAW_TEMPLATE',
            'severity' => 'wajib',
            'observed' => 'Volume XX',
            'expected' => 'Volume riil',
            'recommendation' => 'Ganti placeholder template',
        ]);

        $reconciler = new FusionReconciler();
        $result = $reconciler->reconcile($page);

        $this->assertCount(1, $result['agreed']);
        $this->assertCount(1, $result['machine_only']);
        $this->assertCount(0, $result['ai_only']);
        $this->assertSame('DOCUMENT.RAW_TEMPLATE', $result['machine_only'][0]->rule_code);
    }

    public function test_fusion_auto_learning_promotes_to_active_when_confirmed(): void
    {
        $user = User::factory()->create();
        $pub = Publication::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'owner_id' => $user->id,
            'title' => 'Test Publikasi',
            'region' => 'Ampana',
            'year' => 2026,
            'kind' => 'kcda',
            'audit_mode' => 'fusion',
            'pdf_path' => 'dummy.pdf',
            'pdf_name' => 'dummy.pdf',
            'pdf_checksum' => 'abc',
            'template_path' => 'dummy.xlsx',
            'template_name' => 'dummy.xlsx',
            'template_checksum' => 'def',
            'page_count' => 10,
            'manifest' => [],
            'rule_version' => 'bps-2026.10.18-v2',
        ]);
        $page = $pub->pages()->create(['physical_page' => 10]);

        $candidate = FusionCandidate::create([
            'publication_id' => $pub->id,
            'publication_page_id' => $page->id,
            'physical_page' => 10,
            'engine' => 'machine',
            'rule_code' => 'TABLE.NO_KATA_TAHUN',
            'severity' => 'wajib',
            'observed' => 'Tabel 1.1 Jumlah Penduduk, Tahun 2025',
            'expected' => 'Tabel 1.1 Jumlah Penduduk, 2025',
            'recommendation' => 'Hapus kata Tahun',
        ]);

        $learningService = new FusionLearningService();

        // Ingest confirmation from AI Adjudication
        $learningService->ingestAdjudication($candidate, 'confirm', 'Teks terbukti memuat kata Tahun');

        $learning = ExaminerLearning::where('rule_code', 'TABLE.NO_KATA_TAHUN')->first();
        $this->assertNotNull($learning);
        $this->assertSame('ai_blind_spot', $learning->kind);
        $this->assertSame('active', $learning->status, 'Learning must be automatically promoted to active!');
        $this->assertStringContainsString('TABLE.NO_KATA_TAHUN', $learning->instruction);
    }
}
