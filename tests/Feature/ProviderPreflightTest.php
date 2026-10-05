<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\NineRouterClient;
use App\Models\AiProviderConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProviderPreflightTest extends TestCase
{
    use RefreshDatabase;

    public function test_preflight_checks_a_real_pdf_page_after_the_random_visual_challenge(): void
    {
        Storage::fake('local');
        $config = AiProviderConfig::create(['base_url' => 'https://9router.com/v1', 'api_key' => 'test', 'model' => 'vision', 'created_by' => User::factory()->create()->id]);
        $client = new class extends NineRouterClient
        {
            protected function visionChallenge(): string
            {
                return 'ABC123';
            }
        };
        Http::fakeSequence()->push(['choices' => [['message' => ['content' => '{"code":"ABC123","total":42}']]]])->push(['choices' => [['message' => ['content' => '{"heading_id":"PENJELASAN TEKNIS","heading_en":"TECHNICAL NOTES","printed_page":"13","header":"PEMERINTAHAN"}']]]]);
        $client->testVision($config);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_starts_with($request['messages'][0]['content'][1]['image_url']['url'], 'data:image/jpeg;base64,'));
    }

    public function test_text_only_success_does_not_pass_vision_preflight(): void
    {
        $config = AiProviderConfig::create(['base_url' => 'https://9router.com/v1', 'api_key' => 'test', 'model' => 'text', 'created_by' => User::factory()->create()->id]);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '{"ok":true}']]]])]);
        $this->expectException(\RuntimeException::class);
        app(NineRouterClient::class)->testVision($config);
    }
}
