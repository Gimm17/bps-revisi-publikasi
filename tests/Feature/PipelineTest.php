<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\AuditCoordinator;
use App\Audit\NineRouterClient;
use App\Jobs\AnalyzePage;
use App\Jobs\FinalizeAudit;
use App\Jobs\RenderPage;
use App\Jobs\WriteWorkbook;
use App\Models\AiProviderConfig;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\PublicationFixtures;
use Tests\TestCase;

class PipelineTest extends TestCase
{
    use PublicationFixtures,RefreshDatabase;

    private function setupPub(): Publication
    {
        Storage::fake('local');
        Queue::fake();
        $this->withoutVite();
        $user = User::factory()->create();
        $this->actingAs($user)->post('/publications', ['title' => 'Publikasi Uji 2026', 'region' => 'Uji', 'year' => 2026, 'kind' => 'kcda', 'pdf' => $this->pdfFile(3)])->assertSessionHasNoErrors();
        $config = AiProviderConfig::create(['base_url' => 'https://9router.com/v1', 'api_key' => 'test-key', 'model' => 'vision', 'vision_tested_at' => now(), 'created_by' => $user->id]);
        $pub = Publication::firstOrFail();
        app(AuditCoordinator::class)->start($pub);

        return $pub->fresh();
    }

    public function test_every_page_is_rendered_validated_cross_checked_and_exported_idempotently(): void
    {
        $pub = $this->setupPub();
        Http::fake(function ($request) {
            preg_match('/HALAMAN FISIK PDF (\d+)/', $request['messages'][1]['content'][0]['text'], $match);
            $result = $this->pageResult((int) $match[1], ['text' => 'ISSN: 1234-5679']);

            return Http::response(['id' => 'request-'.$match[1], 'model' => 'vision', 'choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode($result)]]], 'usage' => ['total_tokens' => 10]]);
        });
        foreach ($pub->pages as $page) {
            app()->call([new RenderPage($page->id), 'handle']);
            app()->call([new AnalyzePage($page->id), 'handle']);
            $this->assertSame('complete', $page->fresh()->status);
            app()->call([new AnalyzePage($page->id), 'handle']);
        }
        $this->assertSame(3, $pub->pages()->where('status', 'complete')->count());
        $this->assertDatabaseCount('ai_usage_records', 3);
        $f = $pub->findings()->where('rule_code', 'ISSN.COLON')->firstOrFail();
        $this->assertCount(3, $f->evidences);
        $this->assertSame(1, $f->evidences[0]['physical_page']);
        app()->call([new FinalizeAudit($pub->id), 'handle']);
        $export = $pub->exports()->firstOrFail();
        app()->call([new WriteWorkbook($export->id), 'handle']);
        $this->assertSame('review_ready', $pub->fresh()->status);
        $this->assertSame('complete', $export->fresh()->status);
        Storage::disk('local')->assertExists($export->fresh()->path);
        app()->call([new FinalizeAudit($pub->id), 'handle']);
        app()->call([new WriteWorkbook($export->id), 'handle']);
        $this->assertDatabaseCount('exports', 1);
    }

    public function test_wrong_page_is_never_marked_complete(): void
    {
        $pub = $this->setupPub();
        $page = $pub->pages()->first();
        app()->call([new RenderPage($page->id), 'handle']);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => json_encode($this->pageResult(999))]]]])]);
        try {
            app()->call([new AnalyzePage($page->id), 'handle']);
            $this->fail('Wrong page accepted');
        } catch (ValidationException) {
        }
        $this->assertNotSame('complete', $page->fresh()->status);
        $this->assertFalse(app(AuditCoordinator::class)->canComplete($pub));
    }

    public function test_uncertain_page_escalates_resolution_and_then_blocks_completion(): void
    {
        $pub = $this->setupPub();
        $page = $pub->pages()->first();
        app()->call([new RenderPage($page->id), 'handle']);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => json_encode($this->pageResult(1, ['readability' => 'uncertain']))]]]])]);
        app()->call([new AnalyzePage($page->id), 'handle']);
        $this->assertSame(240, $page->fresh()->dpi);
        app()->call([new RenderPage($page->id), 'handle']);
        app()->call([new AnalyzePage($page->id, 240), 'handle']);
        $this->assertSame('needs_verification', $page->fresh()->status);
        $this->assertSame('partial_failure', $pub->fresh()->status);
    }

    public function test_truncated_provider_response_is_rejected(): void
    {
        $pub = $this->setupPub();
        Http::fake(['*' => Http::response(['choices' => [['finish_reason' => 'length', 'message' => ['content' => '{}']]]])]);
        $this->expectException(\RuntimeException::class);
        app(NineRouterClient::class)->request($pub->aiConfig,[]);
    }
}
