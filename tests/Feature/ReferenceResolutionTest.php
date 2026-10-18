<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\PublicationBrowserClient;
use App\Audit\PublicationSearchClient;
use App\Jobs\FetchPublicationReference;
use App\Models\Publication;
use App\Models\PublicationCheckRun;
use App\Models\PublicationReference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PublicationFixtures;
use Tests\TestCase;

class ReferenceResolutionTest extends TestCase
{
    use PublicationFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_same_named_district_in_other_regency_is_not_used(): void
    {
        $user = User::factory()->create();
        $publication = Publication::create([
            'owner_id' => $user->id,
            'title' => 'Kecamatan Ampana Kota Dalam Angka 2026',
            'region' => 'Ampana Kota',
            'year' => 2026,
            'kind' => 'kcda',
            'context' => [
                'province' => 'Sulawesi Tengah',
                'regency' => 'Tojo Una-Una',
                'district' => 'Ampana Kota',
            ],
            'pdf_path' => 'dummy.pdf',
            'pdf_name' => 'dummy.pdf',
            'pdf_checksum' => hash('sha256', 'dummy'),
            'template_path' => 'template.xlsx',
            'template_name' => 'template.xlsx',
            'template_checksum' => hash('sha256', 'tpl'),
            'page_count' => 10,
            'manifest' => [],
            'rule_version' => 'bps-2026.10.18-v2',
        ]);

        $run = PublicationCheckRun::create([
            'publication_id' => $publication->id,
            'requested_by' => $user->id,
            'mode' => 'supplemental',
            'baseline_rule_version' => 'bps-2026.10.05-v1',
            'target_rule_version' => 'bps-2026.10.18-v2',
            'prompt_version' => 'page-v2',
            'pdf_sha256' => $publication->pdf_checksum,
            'rules_path' => 'audit-rules/pedoman-v2.md',
            'rules_hash' => hash('sha256', 'rules'),
            'scope' => ['volume'],
            'status' => 'pending',
        ]);

        $sentQueries = [];
        $mockSearch = new class($sentQueries) implements PublicationSearchClient
        {
            public function __construct(public array &$queries) {}

            public function search(string $query, int $limit = 5): array
            {
                $this->queries[] = $query;

                return [
                    [
                        'rank' => 1,
                        'title' => 'Kecamatan Ampana Kota Dalam Angka 2025 - BPS Kabupaten Lain',
                        'url' => 'https://kabupatenlain.bps.go.id/publication/view/wrong',
                        'snippet' => 'Kabupaten Lain Dalam Angka',
                    ],
                ];
            }
        };

        $mockBrowser = new class implements PublicationBrowserClient
        {
            public function inspect(string $url): array
            {
                return [
                    'access_status' => 'opened',
                    'read_status' => 'readable',
                    'metadata' => [
                        'title' => 'Kecamatan Ampana Kota Dalam Angka 2025',
                        'regency' => 'Kabupaten Lain', // Different regency
                        'volume' => 31,
                        'year' => 2025,
                    ],
                    'artifacts' => [],
                    'error_code' => null,
                    'error_message' => null,
                ];
            }
        };

        $job = new FetchPublicationReference($run->id);
        $job->handle($mockSearch, $mockBrowser);

        $this->assertSame('Kecamatan Ampana Kota Dalam Angka 2025', $sentQueries[0]);

        $acceptedWrongRegencyReferences = PublicationReference::where('check_run_id', $run->id)
            ->where('read_status', 'readable')
            ->get();

        $this->assertCount(0, $acceptedWrongRegencyReferences);
    }

    public function test_uploaded_screenshot_keeps_user_provenance(): void
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
            'page_count' => 10,
            'manifest' => [],
            'rule_version' => 'bps-2026.10.18-v2',
        ]);

        $run = PublicationCheckRun::create([
            'publication_id' => $publication->id,
            'requested_by' => $user->id,
            'mode' => 'supplemental',
            'baseline_rule_version' => 'bps-2026.10.05-v1',
            'target_rule_version' => 'bps-2026.10.18-v2',
            'prompt_version' => 'page-v2',
            'pdf_sha256' => $publication->pdf_checksum,
            'rules_path' => 'audit-rules/pedoman-v2.md',
            'rules_hash' => hash('sha256', 'rules'),
            'scope' => ['volume'],
            'status' => 'pending',
        ]);

        $uploadedReference = PublicationReference::create([
            'check_run_id' => $run->id,
            'series' => 'kcda',
            'jurisdiction' => 'Ampana Kota',
            'year' => 2025,
            'source_url' => 'https://tojointbps.go.id/publication/view/manual',
            'metadata' => ['volume' => 31],
            'provenance' => 'user_upload',
            'access_status' => 'opened',
            'read_status' => 'readable',
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);

        $this->assertSame('user_upload', $uploadedReference->provenance);
        $this->assertNull($uploadedReference->reviewed_at);
        $this->assertNull($uploadedReference->reviewed_by);
    }
}
