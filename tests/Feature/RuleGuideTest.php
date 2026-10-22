<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RuleGuideTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_can_view_rules_guide_page(): void
    {
        $response = $this->get('/rules');
        $response->assertOk();

        $responseAlias = $this->get('/guidelines');
        $responseAlias->assertOk();
    }

    public function test_authenticated_user_can_view_rules_guide_page(): void
    {
        $user = User::factory()->create([
            'role' => 'examiner',
        ]);

        $response = $this->actingAs($user)->get('/rules');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Rules/Index')
            ->has('version')
            ->has('target_version')
            ->has('summary', fn (Assert $summary) => $summary
                ->where('total_rules', 39)
                ->where('categories_count', 6)
                ->has('mandatory_count')
                ->has('advisory_count')
                ->has('verification_count')
            )
            ->has('categories', 6)
            ->has('stages', 11)
            ->has('rules', 39)
            ->where('rules.0.code', 'COVER.COMPONENTS')
        );
    }

    public function test_rules_guide_contains_all_key_metadata(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/rules');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Rules/Index')
            ->has('rules.0', fn (Assert $rule) => $rule
                ->has('code')
                ->has('title')
                ->has('category')
                ->has('severity')
                ->has('mode')
                ->has('spec_ref')
                ->has('summary')
                ->has('how_it_works')
                ->has('logic')
                ->has('criteria_pass')
                ->has('criteria_fail')
                ->has('example_good')
                ->has('example_bad')
                ->has('recommendation')
            )
        );
    }
}
