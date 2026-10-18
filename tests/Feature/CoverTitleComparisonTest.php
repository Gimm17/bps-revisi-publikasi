<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\CoverTitleComparator;
use App\Audit\NineRouterClient;
use App\Models\AiProviderConfig;
use App\Models\Publication;
use App\Models\PublicationCheckRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PublicationFixtures;
use Tests\TestCase;

class CoverTitleComparisonTest extends TestCase
{
    use PublicationFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_pair_request_contains_both_labeled_images(): void
    {
        $user = User::factory()->create();
        $aiConfig = AiProviderConfig::create([
            'base_url' => 'https://9router.com/v1',
            'api_key' => 'test-key',
            'model' => 'gpt-4o',
            'tested_at' => now(),
            'vision_tested_at' => now(),
            'created_by' => $user->id,
        ]);

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

        // Create images on fake disk
        Storage::disk('local')->put("publications/{$publication->id}/pages/1-160.jpg", 'image-cover-bytes');
        Storage::disk('local')->put("publications/{$publication->id}/pages/3-160.jpg", 'image-title-bytes');

        $publication->pages()->create(['physical_page' => 1, 'page_type' => 'front_cover', 'image_path' => "publications/{$publication->id}/pages/1-160.jpg", 'status' => 'complete']);
        $publication->pages()->create(['physical_page' => 3, 'page_type' => 'title', 'image_path' => "publications/{$publication->id}/pages/3-160.jpg", 'status' => 'complete']);

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
            'scope' => ['cover_title'],
            'status' => 'pending',
        ]);

        $sentImageParts = [];
        $comparedPhysicalPages = [];

        $mockClient = $this->createMock(NineRouterClient::class);
        $mockClient->expects($this->once())
            ->method('request')
            ->willReturnCallback(function ($config, $messages) use (&$sentImageParts, &$comparedPhysicalPages) {
                $userContent = $messages[0]['content'] ?? [];
                foreach ($userContent as $part) {
                    if (($part['type'] ?? '') === 'image_url') {
                        $sentImageParts[] = $part;
                    }
                }
                $comparedPhysicalPages = [1, 3];

                return [
                    'result' => [
                        'readability' => 'readable',
                        'components' => [
                            'logo' => ['decision' => 'conform', 'notes' => 'Logo BPS ada pada kedua halaman.'],
                            'title' => ['decision' => 'conform', 'notes' => 'Judul dan susunan baris selaras.'],
                            'background' => ['decision' => 'conform', 'notes' => 'Halaman judul putih bersih.'],
                        ],
                        'findings' => [],
                    ],
                    'request_id' => 'req-pair-123',
                    'model' => 'gpt-4o',
                    'usage' => ['total_tokens' => 500],
                ];
            });

        $comparator = new CoverTitleComparator($mockClient);
        $whiteTitleResult = $comparator->compare($run, 1, 3);

        $this->assertCount(2, $sentImageParts);
        $this->assertSame([1, 3], $comparedPhysicalPages);
        $this->assertSame('conform', $whiteTitleResult['components']['logo']['decision']);
        $this->assertSame('conform', $whiteTitleResult['decision']);
    }

    public function test_blurred_pair_is_not_conform(): void
    {
        $mockClient = $this->createMock(NineRouterClient::class);
        $mockClient->method('request')->willReturn([
            'result' => [
                'readability' => 'uncertain',
                'components' => [],
                'findings' => [],
            ],
            'request_id' => 'req-blur-456',
            'model' => 'gpt-4o',
            'usage' => [],
        ]);

        $run = $this->createMock(PublicationCheckRun::class);
        $publication = $this->createMock(Publication::class);
        $publication->method('getAttribute')->willReturnMap([
            ['id', 'pub-test-123'],
            ['aiConfig', new AiProviderConfig(['base_url' => 'https://9router.com/v1', 'api_key' => 'key', 'model' => 'gpt-4o'])],
        ]);
        $run->method('getAttribute')->willReturnMap([
            ['publication', $publication],
        ]);

        Storage::disk('local')->put('publications/pub-test-123/pages/1-160.jpg', 'img1');
        Storage::disk('local')->put('publications/pub-test-123/pages/3-160.jpg', 'img3');

        $comparator = new CoverTitleComparator($mockClient);
        $blurredResult = $comparator->compare($run, 1, 3);

        $this->assertSame('needs_verification', $blurredResult['decision']);
    }
}
