<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\AdditionalCheckCoordinator;
use App\Audit\CoverageService;
use App\Models\Publication;
use App\Models\PublicationCheck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PublicationFixtures;
use Tests\TestCase;

class AdditionalCheckWorkflowTest extends TestCase
{
    use PublicationFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
    }

    public function test_supplemental_run_preserves_114_completed_pages(): void
    {
        $user = User::factory()->create();
        $publication = Publication::create([
            'owner_id' => $user->id,
            'title' => 'Kecamatan Ampana Kota Dalam Angka 2026',
            'region' => 'Ampana Kota',
            'year' => 2026,
            'kind' => 'kcda',
            'pdf_path' => 'dummy.pdf',
            'pdf_name' => 'dummy.pdf',
            'pdf_checksum' => hash('sha256', 'dummy'),
            'template_path' => 'template.xlsx',
            'template_name' => 'template.xlsx',
            'template_checksum' => hash('sha256', 'tpl'),
            'page_count' => 114,
            'manifest' => [],
            'rule_version' => 'bps-2026.10.05-v1',
            'status' => 'review_ready',
        ]);

        for ($i = 1; $i <= 114; $i++) {
            $publication->pages()->create([
                'physical_page' => $i,
                'status' => 'complete',
                'result' => $this->pageResult($i),
                'completed_at' => now(),
            ]);
        }

        $finding = $publication->findings()->create([
            'fingerprint' => hash('sha256', 'existing-finding'),
            'rule_code' => 'ISSN.COLON',
            'section' => 'Katalog',
            'severity' => 'wajib',
            'source' => 'deterministic',
            'observed' => 'ISSN 1234-5678',
            'expected' => 'ISSN: 1234-5678',
            'recommendation' => 'Ubah menjadi ISSN: 1234-5678',
            'evidences' => [['physical_page' => 3, 'quote' => 'ISSN 1234-5678']],
            'review_status' => 'accepted',
            'review_note' => 'Disetujui oleh reviewer',
        ]);

        $beforeReviewedFindings = $publication->findings()->where('review_status', 'accepted')->count();

        $coordinator = app(AdditionalCheckCoordinator::class);
        $run = $coordinator->start($publication, $user, ['print_layout']);

        $this->assertSame(114, $publication->fresh()->pages()->where('status', 'complete')->count());
        $this->assertSame($beforeReviewedFindings, $publication->fresh()->findings()->where('review_status', 'accepted')->count());
        $this->assertNotNull($run);
    }

    public function test_duplicate_jobs_are_idempotent(): void
    {
        $user = User::factory()->create();
        $publication = Publication::create([
            'owner_id' => $user->id,
            'title' => 'Publikasi Idempoten',
            'region' => 'Ampana Kota',
            'year' => 2026,
            'kind' => 'kcda',
            'pdf_path' => 'dummy.pdf',
            'pdf_name' => 'dummy.pdf',
            'pdf_checksum' => hash('sha256', 'dummy'),
            'template_path' => 'template.xlsx',
            'template_name' => 'template.xlsx',
            'template_checksum' => hash('sha256', 'tpl'),
            'page_count' => 5,
            'manifest' => [],
            'rule_version' => 'bps-2026.10.05-v1',
            'status' => 'review_ready',
        ]);

        $coordinator = app(AdditionalCheckCoordinator::class);
        $run1 = $coordinator->start($publication, $user, ['print_layout']);
        $run2 = $coordinator->start($publication, $user, ['print_layout']);

        $this->assertSame($run1->id, $run2->id);

        $matchingChecks = PublicationCheck::where('check_run_id', $run1->id)->where('check_type', 'print_layout')->get();
        $this->assertSame(1, $matchingChecks->count());
    }

    public function test_page_complete_does_not_hide_pending_reference_check(): void
    {
        $user = User::factory()->create();
        $publication = Publication::create([
            'owner_id' => $user->id,
            'title' => 'Publikasi Pending Reference',
            'region' => 'Ampana Kota',
            'year' => 2026,
            'kind' => 'kcda',
            'pdf_path' => 'dummy.pdf',
            'pdf_name' => 'dummy.pdf',
            'pdf_checksum' => hash('sha256', 'dummy'),
            'template_path' => 'template.xlsx',
            'template_name' => 'template.xlsx',
            'template_checksum' => hash('sha256', 'tpl'),
            'page_count' => 2,
            'manifest' => [],
            'rule_version' => 'bps-2026.10.05-v1',
            'status' => 'review_ready',
        ]);

        for ($i = 1; $i <= 2; $i++) {
            $publication->pages()->create([
                'physical_page' => $i,
                'status' => 'complete',
                'result' => $this->pageResult($i),
                'completed_at' => now(),
            ]);
        }

        $coordinator = app(AdditionalCheckCoordinator::class);
        $run = $coordinator->start($publication, $user, ['volume']);

        // Check is pending
        $coverageService = app(CoverageService::class);
        $coverage = $coverageService->summarize($publication);

        $this->assertSame('partial', $coverage['report_mode']);
        $this->assertFalse($coverage['can_lock']);
    }
}
