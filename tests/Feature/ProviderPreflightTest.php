<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\NineRouterClient;
use App\Models\AiProviderConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ProviderPreflightTest extends TestCase
{
    use RefreshDatabase;

    public function test_limitrouter_can_be_saved_with_default_host_policy_without_exposing_its_key(): void
    {
        $repository = Env::getRepository();
        $previous = $repository->get('AI_ALLOWED_HOSTS');
        $repository->clear('AI_ALLOWED_HOSTS');
        try {
            config(['audit' => require config_path('audit.php')]);
        } finally {
            if ($previous !== null) {
                $repository->set('AI_ALLOWED_HOSTS', $previous);
            }
        }
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->from('/settings')->post('/settings/ai', [
            'base_url' => 'https://limitrouter.com/v1/', 'api_key' => 'test-limitrouter-secret', 'model' => 'vision-test',
        ])->assertRedirect('/settings')->assertSessionHasNoErrors();
        $saved = AiProviderConfig::latest('id')->firstOrFail();
        $this->assertSame('https://limitrouter.com/v1', $saved->base_url);
        $this->assertSame('test-limitrouter-secret', $saved->api_key);
        $this->assertNotSame('test-limitrouter-secret', $saved->getRawOriginal('api_key'));
        $this->assertNull($saved->vision_tested_at);
        $this->get('/settings')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('ai.base_url', 'https://limitrouter.com/v1')->missing('ai.api_key'));
        $this->assertSame('https://9router.com/v1', app(NineRouterClient::class)->validateUrl('https://9router.com/v1'));
    }

    public function test_switching_provider_without_new_key_does_not_reuse_the_previous_provider_secret(): void
    {
        config(['audit.allowed_ai_hosts' => ['9router.com', 'limitrouter.com']]);
        $admin = User::factory()->create(['role' => 'admin']);
        AiProviderConfig::create(['base_url' => 'https://9router.com/v1', 'api_key' => 'old-provider-secret', 'model' => 'vision', 'created_by' => $admin->id]);
        $this->actingAs($admin)->post('/settings/ai', [
            'base_url' => 'https://limitrouter.com/v1', 'api_key' => '', 'model' => 'vision-test',
        ])->assertSessionHasErrors('api_key');
        $this->assertSame(1, AiProviderConfig::count());
    }

    public function test_limitrouter_like_unapproved_host_is_rejected(): void
    {
        config(['audit.allowed_ai_hosts' => ['9router.com', 'limitrouter.com']]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/settings/ai', [
            'base_url' => 'https://limitrouter.com.attacker.test/v1', 'api_key' => 'test-secret', 'model' => 'vision-test',
        ])->assertSessionHasErrors('base_url');
        $this->assertSame(0, AiProviderConfig::count());
    }

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
