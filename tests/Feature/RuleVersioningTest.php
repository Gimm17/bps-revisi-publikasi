<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Publication;
use App\Models\PublicationCheckRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PublicationFixtures;
use Tests\TestCase;

class RuleVersioningTest extends TestCase
{
    use PublicationFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Queue::fake();
    }

    public function test_all_guideline_sections_have_coverage_entries(): void
    {
        $path = base_path('audit-rules/coverage.json');
        $this->assertFileExists($path);

        $coverage = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($coverage);
        $this->assertArrayHasKey('version', $coverage);
        $this->assertSame('bps-2026.10.18-v2', $coverage['version']);
        $this->assertArrayHasKey('sections', $coverage);

        for ($i = 1; $i <= 21; $i++) {
            $key = (string) $i;
            $this->assertArrayHasKey($key, $coverage['sections'], "Section {$key} must be defined in coverage.json");
            $this->assertNotEmpty($coverage['sections'][$key], "Section {$key} must not be empty");

            foreach ($coverage['sections'][$key] as $entry) {
                $this->assertArrayHasKey('spec_ref', $entry);
                $this->assertArrayHasKey('rule_code', $entry);
                $this->assertArrayHasKey('implementation_mode', $entry);
                $this->assertContains($entry['implementation_mode'], [
                    'deterministic', 'ai_page', 'ai_pair', 'external_evidence', 'manual', 'unsupported',
                ]);
                $this->assertArrayHasKey('verification_test', $entry);
                $this->assertArrayHasKey('status', $entry);
            }
        }
    }

    public function test_supplemental_version_does_not_relabel_or_destroy_legacy_audit(): void
    {
        $user = User::factory()->create(['role' => 'examiner']);
        $this->actingAs($user)->post('/publications', [
            'title' => 'Kecamatan Ampana Kota Dalam Angka 2026',
            'region' => 'Ampana Kota',
            'year' => 2026,
            'kind' => 'kcda',
            'pdf' => $this->pdfFile(2),
        ])->assertSessionHasNoErrors();

        $publication = Publication::firstOrFail();
        $this->assertSame('bps-2026.10.05-v1', $publication->rule_version);

        // Mark pages complete
        foreach ($publication->pages as $page) {
            $page->update([
                'status' => 'complete',
                'result' => $this->pageResult($page->physical_page),
                'completed_at' => now(),
            ]);
        }

        // Create a legacy export
        $export = $publication->exports()->create([
            'version' => 1,
            'publication_revision' => $publication->revision,
            'status' => 'complete',
            'checksum' => hash('sha256', 'mock-export-v1'),
            'created_by' => $user->id,
        ]);

        $beforePageResults = $publication->fresh()->pages->pluck('result', 'id')->all();
        $beforeExportHashes = $publication->fresh()->exports->pluck('checksum', 'id')->all();

        // Create a supplemental check run
        $run = PublicationCheckRun::create([
            'publication_id' => $publication->id,
            'requested_by' => $user->id,
            'mode' => 'supplemental',
            'baseline_rule_version' => $publication->rule_version,
            'target_rule_version' => 'bps-2026.10.18-v2',
            'prompt_version' => 'page-v2',
            'pdf_sha256' => $publication->pdf_checksum,
            'rules_path' => 'audit-rules/pedoman-v2.md',
            'rules_hash' => hash('sha256', 'pedoman-v2'),
            'scope' => ['volume', 'cover_title', 'print_layout'],
            'status' => 'pending',
        ]);

        $this->assertSame('bps-2026.10.05-v1', $publication->fresh()->rule_version);
        $this->assertSame('bps-2026.10.18-v2', $run->target_rule_version);
        $this->assertSame($beforePageResults, $publication->fresh()->pages->pluck('result', 'id')->all());
        $this->assertSame($beforeExportHashes, $publication->fresh()->exports->pluck('checksum', 'id')->all());
    }
}
