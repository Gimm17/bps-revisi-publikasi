<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiAgent;
use App\Models\Publication;
use App\Models\PublicationPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentOfficeTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get('/agent-office');
        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_access_agent_office(): void
    {
        $user = User::factory()->create();

        // Create 5 test agents
        for ($i = 1; $i <= 5; $i++) {
            AiAgent::create([
                'slot' => $i,
                'name' => "Agent {$i}",
                'base_url' => 'https://api.test.com',
                'api_key' => 'secret-key-'.$i,
                'model' => 'gemini-3.8-flash',
                'enabled' => true,
                'status' => 'ready',
                'vision_tested_at' => now(),
            ]);
        }

        $response = $this->actingAs($user)->get('/agent-office');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('AgentOffice/Index')
            ->has('initialState.agents', 5)
            ->has('initialState.summary')
            ->has('initialState.recent_activities')
        );
    }

    public function test_state_json_endpoint_returns_valid_structure(): void
    {
        $user = User::factory()->create();

        $agent = AiAgent::create([
            'slot' => 1,
            'name' => 'Agent 1',
            'base_url' => 'https://api.test.com',
            'api_key' => 'secret-key',
            'model' => 'gemini-3.8-flash',
            'enabled' => true,
            'status' => 'ready',
            'vision_tested_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/agent-office/state');
        $response->assertOk();
        $response->assertJsonStructure([
            'agents' => [
                '*' => [
                    'id',
                    'slot',
                    'name',
                    'model',
                    'office_status',
                    'assigned_pages',
                    'total_calls',
                ],
            ],
            'recent_activities',
            'summary' => [
                'total_agents',
                'active_agents',
                'ready_agents',
            ],
            'server_time',
        ]);
    }
}
