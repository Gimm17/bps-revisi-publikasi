<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\AgentPoolSelector;
use App\Audit\AuditCoordinator;
use App\Audit\NineRouterClient;
use App\Jobs\TestAiAgent;
use App\Models\AiAgent;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\PublicationFixtures;
use Tests\TestCase;

class MultiAgentWorkflowTest extends TestCase
{
    use PublicationFixtures, RefreshDatabase;

    public function test_admin_can_save_agent_configuration_slots(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->post('/settings/ai-agents/1', [
            'name' => 'Agent 1 (Utama)',
            'base_url' => 'https://api.9router.com/v1',
            'api_key' => 'secret-key-1',
            'model' => 'gemini-2.5-flash',
            'enabled' => true,
            'max_concurrency' => 2,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('ai_agents', [
            'slot' => 1,
            'name' => 'Agent 1 (Utama)',
            'model' => 'gemini-2.5-flash',
            'enabled' => true,
            'status' => 'untested',
        ]);

        // Copy to Slot 2
        $copyResponse = $this->actingAs($admin)->post('/settings/ai-agents/copy', [
            'from_slot' => 1,
            'to_slot' => 2,
        ]);
        $copyResponse->assertSessionHasNoErrors();
        $this->assertDatabaseHas('ai_agents', [
            'slot' => 2,
            'model' => 'gemini-2.5-flash',
            'enabled' => true,
            'status' => 'untested',
        ]);
    }

    public function test_test_ai_agent_job_verifies_vision_and_marks_ready(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $agent = AiAgent::create([
            'slot' => 1,
            'name' => 'Agent 1',
            'base_url' => 'https://api.9router.com/v1',
            'api_key' => 'valid-key',
            'model' => 'gemini-2.5-flash',
            'enabled' => true,
            'status' => 'untested',
            'created_by' => $admin->id,
        ]);

        $clientMock = $this->createMock(NineRouterClient::class);
        $clientMock->expects($this->once())->method('testVision')->with($this->isInstanceOf(AiAgent::class));

        $job = new TestAiAgent($agent->id);
        $job->handle($clientMock);

        $agent->refresh();
        $this->assertSame('ready', $agent->status);
        $this->assertNotNull($agent->vision_tested_at);
        $this->assertSame('Lulus uji pembacaan gambar.', $agent->test_message);
    }

    public function test_audit_coordinator_requires_at_least_one_ready_ai_agent(): void
    {
        Storage::fake('local');
        Queue::fake();
        Http::preventStrayRequests();
        $this->withoutVite();

        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->post('/publications', [
            'title' => 'Uji Agen',
            'region' => 'BPS Uji',
            'year' => 2026,
            'kind' => 'kcda',
            'pdf' => $this->pdfFile(2),
        ])->assertSessionHasNoErrors();

        $publication = Publication::firstOrFail();
        $coordinator = app(AuditCoordinator::class);

        // No agent is ready yet
        try {
            $coordinator->start($publication);
            $this->fail('Harusnya gagal karena belum ada agent yang siap.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('ai', $e->errors());
        }

        // Set agent 1 as ready
        AiAgent::create([
            'slot' => 1,
            'name' => 'Agent 1',
            'base_url' => 'https://api.9router.com/v1',
            'api_key' => 'valid-key',
            'model' => 'gemini-2.5-flash',
            'enabled' => true,
            'status' => 'ready',
            'vision_tested_at' => now(),
            'config_hash' => 'hash-1',
            'created_by' => $user->id,
        ]);

        $coordinator->start($publication);
        $this->assertSame('processing', $publication->fresh()->status);
        $this->assertCount(1, $publication->fresh()->aiAgents);
    }

    public function test_pool_selector_alternates_between_ready_agents(): void
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
            'vision_tested_at' => now(),
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
            'vision_tested_at' => now(),
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
            'page_count' => 4,
            'manifest' => ['pages' => []],
            'rule_version' => 'v2',
        ]);

        $selector = app(AgentPoolSelector::class);
        $selected1 = $selector->selectAgent($pub);
        $this->assertNotNull($selected1);

        // Exclude selected1 should return the other agent
        $selected2 = $selector->selectAgent($pub, excludeAgentId: $selected1->agentId());
        $this->assertNotNull($selected2);
        $this->assertNotSame($selected1->agentId(), $selected2->agentId());
    }
}
