<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\CrossVerifier;
use App\Models\AiAgent;
use App\Models\Finding;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PublicationFixtures;
use Tests\TestCase;

class CrossVerificationTest extends TestCase
{
    use PublicationFixtures, RefreshDatabase;

    public function test_cross_verifier_identifies_high_risk_pages_and_tables(): void
    {
        $user = User::factory()->create();
        $pub = Publication::create([
            'owner_id' => $user->id,
            'title' => 'Test',
            'region' => 'Reg',
            'year' => 2026,
            'kind' => 'kcda',
            'pdf_path' => 'p.pdf',
            'pdf_name' => 'p.pdf',
            'pdf_checksum' => 'c',
            'template_path' => 't.xlsx',
            'template_name' => 't.xlsx',
            'template_checksum' => 'c',
            'page_count' => 5,
            'manifest' => ['pages' => []],
            'rule_version' => 'v2',
            'cross_verify_mode' => 'high_risk',
        ]);

        $coverPage = $pub->pages()->create([
            'physical_page' => 1,
            'page_type' => 'cover',
            'status' => 'complete',
        ]);

        $bodyPage = $pub->pages()->create([
            'physical_page' => 2,
            'page_type' => 'body',
            'status' => 'complete',
            'result' => ['inventory' => ['tables' => []]],
        ]);

        $tablePage = $pub->pages()->create([
            'physical_page' => 3,
            'page_type' => 'body',
            'status' => 'complete',
            'result' => ['inventory' => ['tables' => [['number' => '1.1', 'title' => 'Tabel Contoh']]]],
        ]);

        $verifier = app(CrossVerifier::class);

        $this->assertTrue($verifier->shouldVerify($coverPage));
        $this->assertFalse($verifier->shouldVerify($bodyPage));
        $this->assertTrue($verifier->shouldVerify($tablePage));
    }

    public function test_reconcile_marks_confirmed_and_single_agent_findings(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $agent1 = AiAgent::create([
            'slot' => 1,
            'name' => 'Agent 1',
            'base_url' => 'https://api.9router.com/v1',
            'api_key' => 'k1',
            'model' => 'm1',
            'enabled' => true,
            'status' => 'ready',
            'created_by' => $admin->id,
        ]);
        $agent2 = AiAgent::create([
            'slot' => 2,
            'name' => 'Agent 2',
            'base_url' => 'https://api.limitrouter.com/v1',
            'api_key' => 'k2',
            'model' => 'm2',
            'enabled' => true,
            'status' => 'ready',
            'created_by' => $admin->id,
        ]);

        $pub = Publication::create([
            'owner_id' => $admin->id,
            'title' => 'Test',
            'region' => 'Reg',
            'year' => 2026,
            'kind' => 'kcda',
            'pdf_path' => 'p.pdf',
            'pdf_name' => 'p.pdf',
            'pdf_checksum' => 'c',
            'template_path' => 't.xlsx',
            'template_name' => 't.xlsx',
            'template_checksum' => 'c',
            'page_count' => 1,
            'manifest' => ['pages' => []],
            'rule_version' => 'v2',
            'cross_verify_mode' => 'high_risk',
        ]);

        $page = $pub->pages()->create([
            'physical_page' => 1,
            'page_type' => 'cover',
            'status' => 'complete',
            'ai_agent_id' => $agent1->id,
        ]);

        // Finding 1 by Agent 1
        $f1 = Finding::create([
            'publication_id' => $pub->id,
            'fingerprint' => 'fp-cover-title',
            'rule_code' => 'COVER.TITLE',
            'section' => 'cover',
            'severity' => 'wajib',
            'source' => 'ai_visual',
            'confidence' => 0.9,
            'observed' => 'Judul salah',
            'expected' => 'Judul benar',
            'recommendation' => 'Perbaiki judul',
            'evidences' => [['page' => 1]],
            'review_status' => 'unreviewed',
            'version' => 1,
            'deleted' => false,
            'agent_ids' => [$agent1->id],
        ]);

        // Finding 2 by Agent 1 (will not be confirmed by Agent 2)
        $f2 = Finding::create([
            'publication_id' => $pub->id,
            'fingerprint' => 'fp-cover-logo',
            'rule_code' => 'COVER.LOGO',
            'section' => 'cover',
            'severity' => 'saran',
            'source' => 'ai_visual',
            'confidence' => 0.8,
            'observed' => 'Logo agak buram',
            'expected' => 'Logo tajam',
            'recommendation' => 'Ganti logo',
            'evidences' => [['page' => 1]],
            'review_status' => 'unreviewed',
            'version' => 1,
            'deleted' => false,
            'agent_ids' => [$agent1->id],
        ]);

        // Agent 2 analysis result
        $agent2Result = [
            'readability' => 'readable',
            'page_type' => 'cover',
            'printed_page' => null,
            'findings' => [
                [
                    'rule_code' => 'COVER.TITLE',
                    'severity' => 'wajib',
                    'observed' => 'Judul salah cetak',
                    'expected' => 'Judul benar',
                    'recommendation' => 'Perbaiki judul',
                    'quote' => 'Judul salah',
                ],
                [
                    // Novel finding by Agent 2
                    'rule_code' => 'COVER.ISBN',
                    'severity' => 'wajib',
                    'observed' => 'ISBN tidak tercantum',
                    'expected' => 'Ada ISBN',
                    'recommendation' => 'Cantumkan ISBN',
                    'quote' => '',
                ],
            ],
            'inventory' => [],
        ];

        $verifier = app(CrossVerifier::class);
        $verification = $verifier->reconcile($page, $agent2, $agent2Result);

        $this->assertSame('completed', $verification->status);
        $this->assertSame(1, $verification->agreement['confirmed_count']);
        $this->assertSame(2, $verification->agreement['single_agent_count']);

        // Check f1 is confirmed
        $this->assertSame('confirmed', $f1->fresh()->agreement);
        $this->assertContains($agent1->id, $f1->fresh()->agent_ids);
        $this->assertContains($agent2->id, $f1->fresh()->agent_ids);

        // Check f2 is single_agent
        $this->assertSame('single_agent', $f2->fresh()->agreement);

        // Novel finding created by agent 2 has single_agent
        $novel = Finding::where('publication_id', $pub->id)->where('rule_code', 'COVER.ISBN')->first();
        $this->assertNotNull($novel);
        $this->assertSame('single_agent', $novel->agreement);
        $this->assertContains($agent2->id, $novel->agent_ids);
    }

    public function test_table_alignment_heuristics(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $agent2 = AiAgent::create([
            'slot' => 2,
            'name' => 'Agent 2',
            'base_url' => 'https://api.limitrouter.com/v1',
            'api_key' => 'k2',
            'model' => 'm2',
            'enabled' => true,
            'status' => 'ready',
            'created_by' => $admin->id,
        ]);

        $pub = Publication::create([
            'owner_id' => $admin->id,
            'title' => 'Test',
            'region' => 'Reg',
            'year' => 2026,
            'kind' => 'kcda',
            'pdf_path' => 'p.pdf',
            'pdf_name' => 'p.pdf',
            'pdf_checksum' => 'c',
            'template_path' => 't.xlsx',
            'template_name' => 't.xlsx',
            'template_checksum' => 'c',
            'page_count' => 1,
            'manifest' => ['pages' => []],
            'rule_version' => 'v2',
            'cross_verify_mode' => 'high_risk',
        ]);

        $page = $pub->pages()->create([
            'physical_page' => 1,
            'page_type' => 'body',
            'status' => 'complete',
            'ai_agent_id' => 1,
        ]);

        // Agent 2 reports numbers aligned left or text aligned right in tables
        $agent2Result = [
            'readability' => 'readable',
            'page_type' => 'body',
            'printed_page' => '10',
            'findings' => [],
            'inventory' => [
                'tables' => [
                    [
                        'number' => '1.1',
                        'title' => 'Luas Wilayah',
                        'columns' => [
                            ['label' => 'Kecamatan', 'alignment' => 'right'], // anomalous text right-aligned
                            ['label' => 'Jumlah', 'alignment' => 'left'],      // anomalous numbers left-aligned
                        ],
                    ],
                ],
            ],
        ];

        $verifier = app(CrossVerifier::class);
        $verification = $verifier->reconcile($page, $agent2, $agent2Result);

        $this->assertTrue($verification->agreement['table_alignment_checked']);
        // Verify finding was created for table format alignment
        $finding = Finding::where('publication_id', $pub->id)->where('rule_code', 'TABLE.FORMAT')->first();
        $this->assertNotNull($finding);
        $this->assertStringContainsString('perataan teks/angka', $finding->observed);
    }
}
