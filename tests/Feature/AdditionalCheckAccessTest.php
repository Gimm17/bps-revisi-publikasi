<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FindingAssessment;
use App\Models\Publication;
use App\Models\PublicationCheck;
use App\Models\PublicationCheckRun;
use App\Models\PublicationReference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PublicationFixtures;
use Tests\TestCase;

class AdditionalCheckAccessTest extends TestCase
{
    use PublicationFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
    }

    private function createPublication(User $owner): Publication
    {
        $pub = Publication::create([
            'owner_id' => $owner->id,
            'title' => 'Publikasi Uji '.$owner->id,
            'region' => 'Uji',
            'year' => 2026,
            'kind' => 'kcda',
            'pdf_path' => 'dummy.pdf',
            'pdf_name' => 'dummy.pdf',
            'pdf_checksum' => hash('sha256', 'dummy'.$owner->id),
            'template_path' => 'template.xlsx',
            'template_name' => 'template.xlsx',
            'template_checksum' => hash('sha256', 'tpl'),
            'page_count' => 3,
            'manifest' => [],
            'rule_version' => 'bps-2026.10.18-v2',
            'status' => 'review_ready',
        ]);

        return $pub;
    }

    public function test_foreign_publication_reference_is_forbidden(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $pubA = $this->createPublication($ownerA);

        $runA = PublicationCheckRun::create([
            'publication_id' => $pubA->id,
            'requested_by' => $ownerA->id,
            'mode' => 'supplemental',
            'baseline_rule_version' => $pubA->rule_version,
            'target_rule_version' => 'bps-2026.10.18-v2',
            'prompt_version' => 'page-v2',
            'pdf_sha256' => $pubA->pdf_checksum,
            'rules_path' => 'audit-rules/pedoman-v2.md',
            'rules_hash' => hash('sha256', 'v2'),
            'scope' => ['volume'],
            'status' => 'completed',
        ]);

        $checkA = PublicationCheck::create([
            'check_run_id' => $runA->id,
            'check_type' => 'volume',
            'input_hash' => hash('sha256', 'chkA'),
            'execution_status' => 'failed',
            'decision' => 'needs_verification',
        ]);

        $refA = PublicationReference::create([
            'check_run_id' => $runA->id,
            'series' => 'Kecamatan Ampana Kota Dalam Angka',
            'jurisdiction' => 'Ampana Kota',
            'year' => 2025,
            'source_url' => 'https://sulteng.bps.go.id/ref.html',
            'provenance' => 'official_browser',
            'access_status' => 'opened',
            'read_status' => 'readable',
            'artifact_manifest' => [
                ['id' => 1, 'artifact_type' => 'cover_image', 'path' => 'ref/cover.jpg', 'checksum' => hash('sha256', 'cvr')],
            ],
        ]);

        $findingA = $pubA->findings()->create([
            'fingerprint' => hash('sha256', 'fA'),
            'rule_code' => 'VOLUME.HISTORY',
            'section' => 'Kover',
            'severity' => 'wajib',
            'source' => 'deterministic',
            'observed' => 'Jilid 11',
            'expected' => 'Jilid 10',
            'recommendation' => 'Ubah jilid',
            'evidences' => [['physical_page' => 1, 'quote' => 'Jilid 11']],
        ]);

        $assessmentA = FindingAssessment::create([
            'finding_id' => $findingA->id,
            'check_run_id' => $runA->id,
            'proposed_decision' => 'dismiss',
            'reason' => 'Sudah benar',
        ]);

        // Foreign user B cannot perform checks, retries, references, artifacts, or assessments on Pub A
        $this->actingAs($ownerB)
            ->post('/publications/'.$pubA->id.'/checks', ['scope' => ['volume']])
            ->assertForbidden();

        $this->actingAs($ownerB)
            ->post('/publications/'.$pubA->id.'/checks/'.$checkA->id.'/retry')
            ->assertForbidden();

        $this->actingAs($ownerB)
            ->post('/publications/'.$pubA->id.'/references', [
                'file' => UploadedFile::fake()->create('ref.pdf', 100, 'application/pdf'),
                'series' => 'KCDA',
                'year' => 2025,
            ])
            ->assertForbidden();

        $this->actingAs($ownerB)
            ->get('/publications/'.$pubA->id.'/references/'.$refA->id.'/artifacts/1')
            ->assertForbidden();

        $this->actingAs($ownerB)
            ->patch('/publications/'.$pubA->id.'/assessments/'.$assessmentA->id, [
                'decision' => 'accepted',
            ])
            ->assertForbidden();
    }

    public function test_scoped_idor_returns_not_found(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $pubA = $this->createPublication($ownerA);
        $pubB = $this->createPublication($ownerB);

        $runB = PublicationCheckRun::create([
            'publication_id' => $pubB->id,
            'requested_by' => $ownerB->id,
            'mode' => 'supplemental',
            'baseline_rule_version' => $pubB->rule_version,
            'target_rule_version' => 'bps-2026.10.18-v2',
            'prompt_version' => 'page-v2',
            'pdf_sha256' => $pubB->pdf_checksum,
            'rules_path' => 'audit-rules/pedoman-v2.md',
            'rules_hash' => hash('sha256', 'v2'),
            'scope' => ['volume'],
            'status' => 'completed',
        ]);

        $checkB = PublicationCheck::create([
            'check_run_id' => $runB->id,
            'check_type' => 'volume',
            'input_hash' => hash('sha256', 'chkB'),
            'execution_status' => 'failed',
        ]);

        $refB = PublicationReference::create([
            'check_run_id' => $runB->id,
            'series' => 'Kecamatan Lain Dalam Angka',
            'source_url' => 'https://sulteng.bps.go.id/refB.html',
            'provenance' => 'official_browser',
            'access_status' => 'opened',
            'read_status' => 'readable',
            'artifact_manifest' => [
                ['id' => 2, 'artifact_type' => 'cover_image', 'path' => 'ref/coverB.jpg', 'checksum' => hash('sha256', 'cvrB')],
            ],
        ]);

        $findingB = $pubB->findings()->create([
            'fingerprint' => hash('sha256', 'fB'),
            'rule_code' => 'VOLUME.HISTORY',
            'section' => 'Kover',
            'severity' => 'wajib',
            'source' => 'deterministic',
            'observed' => 'Jilid 11',
            'expected' => 'Jilid 10',
            'recommendation' => 'Ubah jilid',
            'evidences' => [['physical_page' => 1, 'quote' => 'Jilid 11']],
        ]);

        $assessmentB = FindingAssessment::create([
            'finding_id' => $findingB->id,
            'check_run_id' => $runB->id,
            'proposed_decision' => 'dismiss',
            'reason' => 'Sudah benar',
        ]);

        // Owner A attempting to access items belonging to Pub B through Pub A endpoint receives 404
        $this->actingAs($ownerA)
            ->post('/publications/'.$pubA->id.'/checks/'.$checkB->id.'/retry')
            ->assertNotFound();

        $this->actingAs($ownerA)
            ->get('/publications/'.$pubA->id.'/references/'.$refB->id.'/artifacts/2')
            ->assertNotFound();

        $this->actingAs($ownerA)
            ->patch('/publications/'.$pubA->id.'/assessments/'.$assessmentB->id, ['decision' => 'accepted'])
            ->assertNotFound();
    }

    public function test_owner_can_start_supplemental_check_with_allowlist_scope(): void
    {
        $owner = User::factory()->create();
        $pub = $this->createPublication($owner);

        // Disallowed scope should be rejected
        $this->actingAs($owner)
            ->post('/publications/'.$pub->id.'/checks', ['scope' => ['unsupported_scope']])
            ->assertSessionHasErrors('scope.0');

        // Allowed scope succeeds
        $this->actingAs($owner)
            ->post('/publications/'.$pub->id.'/checks', ['scope' => ['volume', 'print_layout']])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('publication_check_runs', [
            'publication_id' => $pub->id,
        ]);
    }

    public function test_reviewer_can_accept_assessment_and_update_finding(): void
    {
        $reviewer = User::factory()->create(['role' => 'reviewer']);
        $pub = $this->createPublication($reviewer);

        $run = PublicationCheckRun::create([
            'publication_id' => $pub->id,
            'requested_by' => $reviewer->id,
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

        $finding = $pub->findings()->create([
            'fingerprint' => hash('sha256', 'f-test'),
            'rule_code' => 'VOLUME.HISTORY',
            'section' => 'Kover',
            'severity' => 'wajib',
            'source' => 'deterministic',
            'observed' => 'Jilid 11',
            'expected' => 'Jilid 10',
            'recommendation' => 'Ubah jilid',
            'evidences' => [['physical_page' => 1, 'quote' => 'Jilid 11']],
            'review_status' => 'accepted',
        ]);

        $assessment = FindingAssessment::create([
            'finding_id' => $finding->id,
            'check_run_id' => $run->id,
            'proposed_decision' => 'dismiss',
            'reason' => 'Volume terverifikasi mandiri',
        ]);

        $this->actingAs($reviewer)
            ->patch('/publications/'.$pub->id.'/assessments/'.$assessment->id, [
                'decision' => 'accepted',
                'reason' => 'Persetujuan reviewer atas usulan pembatalan',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $assessment->refresh();
        $this->assertNotNull($assessment->reviewed_at);
        $this->assertSame($reviewer->id, $assessment->reviewer_id);

        $finding->refresh();
        $this->assertSame('rejected', $finding->review_status);
        $this->assertStringContainsString('Persetujuan reviewer', $finding->review_note);
    }
}
