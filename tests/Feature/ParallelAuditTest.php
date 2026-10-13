<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\AiCapacity;
use App\Audit\AiRequestException;
use App\Audit\AuditCoordinator;
use App\Audit\NineRouterClient;
use App\Jobs\AnalyzePage;
use App\Jobs\RenderPage;
use App\Models\AiProviderConfig;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PublicationFixtures;
use Tests\TestCase;

class ParallelAuditTest extends TestCase
{
    use PublicationFixtures, RefreshDatabase;

    private function publication(): Publication
    {
        Storage::fake('local');
        Queue::fake();
        Http::preventStrayRequests();
        $this->withoutVite();
        $user = User::factory()->create();
        $this->actingAs($user)->post('/publications', ['title' => 'Paralel', 'region' => 'Uji', 'year' => 2026, 'kind' => 'kcda', 'pdf' => $this->pdfFile(3)])->assertSessionHasNoErrors();
        AiProviderConfig::create(['base_url' => 'https://9router.com/v1', 'api_key' => 'test-key', 'model' => 'vision', 'vision_tested_at' => now(), 'created_by' => $user->id]);
        $pub = Publication::firstOrFail();
        app(AuditCoordinator::class)->start($pub);

        return $pub->fresh();
    }

    public function test_rendered_pages_are_dispatched_to_the_dedicated_ai_queue(): void
    {
        $pub = $this->publication();
        app()->call([new RenderPage($pub->pages()->first()->id), 'handle']);
        Queue::assertPushedOn('ai', AnalyzePage::class);
    }

    public function test_two_slots_are_shared_across_providers_and_the_third_waits(): void
    {
        config(['audit.ai_concurrency' => 2]);
        $first = app(AiCapacity::class)->acquire('9router.com');
        $second = (new AiCapacity)->acquire('limitrouter.com');
        try {
            (new AiCapacity)->acquire('9router.com');
            $this->fail('A third request was allowed');
        } catch (AiRequestException $exception) {
            $this->assertSame('capacity', $exception->category);
        }
        $first->release();
        $third = (new AiCapacity)->acquire('9router.com');
        $this->assertNotNull($third);
        $second->release();
        $third->release();
    }

    public function test_provider_failure_releases_its_slot(): void
    {
        $pub = $this->publication();
        config(['audit.ai_concurrency' => 1]);
        Http::fake(['*' => Http::response([], 401)]);
        try {
            app(NineRouterClient::class)->request($pub->aiConfig, []);
        } catch (\RuntimeException) {
        }
        $lock = app(AiCapacity::class)->acquire('9router.com');
        $this->assertNotNull($lock);
        $lock->release();
    }

    public function test_image_route_failure_stops_without_repeated_paid_attempts(): void
    {
        $pub = $this->publication();
        $page = $pub->pages()->first();
        app()->call([new RenderPage($page->id), 'handle']);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '[image omitted]']]]])]);
        $job = (new AnalyzePage($page->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);
        $job->assertFailed();
        $this->assertSame('failed', $page->fresh()->status);
        $this->assertSame(1, $page->fresh()->attempts);
        $this->assertDatabaseHas('ai_usage_records', ['page_id' => $page->id, 'status' => 'failed_image']);
        $this->assertFalse(app(AuditCoordinator::class)->canComplete($pub));
    }

    public function test_invalid_response_gets_one_corrective_retry_then_stops(): void
    {
        $pub = $this->publication();
        $page = $pub->pages()->first();
        app()->call([new RenderPage($page->id), 'handle']);
        $invalid = $this->pageResult(1, ['contents' => [['type' => 'table', 'title' => 'Tabel', 'target_page' => null]]]);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => json_encode($invalid)]]]])]);
        $original = new AnalyzePage($page->id);
        $first = unserialize(serialize($original))->withFakeQueueInteractions();
        app()->call([$first, 'handle']);
        $first->assertReleased(5);
        $this->assertSame('ready', $page->fresh()->status);
        $second = unserialize(serialize($original))->withFakeQueueInteractions();
        app()->call([$second, 'handle']);
        $second->assertFailed();
        $this->assertSame('failed', $page->fresh()->status);
        $this->assertSame(2, $page->fresh()->attempts);
        $this->assertDatabaseCount('ai_usage_records', 2);
        Http::assertSent(fn ($request) => str_contains($request['messages'][1]['content'][0]['text'], 'contents.0.target_page'));
    }

    public function test_rate_limit_defers_all_requests_to_the_same_host_without_spending_another_attempt(): void
    {
        $pub = $this->publication();
        $page = $pub->pages()->first();
        app()->call([new RenderPage($page->id), 'handle']);
        Http::fake(['*' => Http::response([], 429, ['Retry-After' => '90'])]);
        $job = (new AnalyzePage($page->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);
        $job->assertReleased(90);
        $this->assertSame('ready', $page->fresh()->status);
        $this->assertSame(1, $page->fresh()->attempts);
        $later = (new AnalyzePage($page->id))->withFakeQueueInteractions();
        app()->call([$later, 'handle']);
        $later->assertReleased(90);
        $this->assertSame(1, $page->fresh()->attempts);
        Http::assertSentCount(1);
    }

    public function test_capacity_wait_does_not_call_provider_or_increment_page_attempts(): void
    {
        $pub = $this->publication();
        $page = $pub->pages()->first();
        app()->call([new RenderPage($page->id), 'handle']);
        config(['audit.ai_concurrency' => 1]);
        $lock = app(AiCapacity::class)->acquire('9router.com');
        $job = (new AnalyzePage($page->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);
        $job->assertReleased(10);
        $this->assertSame(0, $page->fresh()->attempts);
        $this->assertDatabaseCount('ai_usage_records', 0);
        Http::assertNothingSent();
        $lock->release();
    }

    public function test_repeated_queue_waits_do_not_exhaust_the_actual_request_budget(): void
    {
        $queues = Queue::getFacadeRoot();
        $pub = $this->publication();
        $page = $pub->pages()->first();
        app()->call([new RenderPage($page->id), 'handle']);
        Queue::swap($queues);
        config(['audit.ai_concurrency' => 1]);
        $lock = app(AiCapacity::class)->acquire('9router.com');
        Queue::connection('database')->push(new AnalyzePage($page->id), '', 'ai');
        $worker = app('queue.worker');
        $options = new WorkerOptions;
        $options->maxTries = 3;
        for ($wait = 0; $wait < 5; $wait++) {
            $worker->runNextJob('database', 'ai', $options);
            $this->travel(11)->seconds();
        }
        $this->assertSame(0, $page->fresh()->attempts);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('failed_jobs', 0);
        Http::assertNothingSent();
        $lock->release();
        Http::fake(['*' => Http::response(['model' => 'vision', 'choices' => [['message' => ['content' => json_encode($this->pageResult())]]]])]);
        $worker->runNextJob('database', 'ai', $options);
        $this->assertSame('complete', $page->fresh()->status);
        $this->assertSame(1, $page->fresh()->attempts);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_format_correction_is_still_allowed_after_one_transient_failure(): void
    {
        $pub = $this->publication();
        $page = $pub->pages()->first();
        app()->call([new RenderPage($page->id), 'handle']);
        Http::fake(['*' => Http::sequence()->push([], 500)->push(['choices' => [['message' => ['content' => 'not json']]]])->push(['choices' => [['message' => ['content' => json_encode($this->pageResult())]]]])]);
        $original = new AnalyzePage($page->id);
        foreach ([30, 5] as $delay) {
            $job = unserialize(serialize($original))->withFakeQueueInteractions();
            app()->call([$job, 'handle']);
            $job->assertReleased($delay);
        }
        $last = unserialize(serialize($original))->withFakeQueueInteractions();
        app()->call([$last, 'handle']);
        $this->assertSame('complete', $page->fresh()->status);
        $this->assertSame(3, $page->fresh()->attempts);
    }

    public function test_preparation_upgrades_only_unreserved_legacy_page_jobs_without_resetting_results(): void
    {
        $pub = $this->publication();
        $page = $pub->pages()->first();
        $page->update(['attempts' => 24]);
        $before = $pub->pages()->get()->toArray();
        $legacy = new AnalyzePage($page->id);
        $legacy->attemptBaseline = null;
        $payload = ['displayName' => AnalyzePage::class, 'maxTries' => 3, 'maxExceptions' => null, 'retryUntil' => null, 'data' => ['command' => serialize($legacy)]];
        $jobId = DB::table('jobs')->insertGetId(['queue' => 'default', 'payload' => json_encode($payload), 'attempts' => 2, 'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);
        $reservedId = DB::table('jobs')->insertGetId(['queue' => 'default', 'payload' => json_encode($payload), 'attempts' => 2, 'reserved_at' => now()->timestamp, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);
        $this->artisan('audit:prepare-parallel')->assertExitCode(0);
        $updated = DB::table('jobs')->find($jobId);
        $newPayload = json_decode($updated->payload, true);
        $this->assertSame(0, $newPayload['maxTries']);
        $this->assertSame(3, $newPayload['maxExceptions']);
        $this->assertGreaterThan(now()->timestamp, $newPayload['retryUntil']);
        $this->assertSame(24, unserialize($newPayload['data']['command'])->attemptBaseline);
        $this->assertSame('default', $updated->queue);
        $this->assertSame(2, $updated->attempts);
        $this->assertSame(json_encode($payload), DB::table('jobs')->find($reservedId)->payload);
        $this->assertSame($before, $pub->pages()->get()->toArray());
        $this->artisan('audit:prepare-parallel')->assertExitCode(0);
        $this->assertSame($updated->payload, DB::table('jobs')->find($jobId)->payload);
    }
}
