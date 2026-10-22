<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\AuditCoordinator;
use App\Jobs\RenderPage;
use App\Models\AiProviderConfig;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PublicationFixtures;
use Tests\TestCase;

class RecoveryTest extends TestCase
{
    use PublicationFixtures,RefreshDatabase;

    private function pub(int $count): Publication
    {
        Storage::fake('local');
        Queue::fake();
        $this->withoutVite();
        $u = User::factory()->create();
        $this->actingAs($u)->post('/publications', ['title' => 'Recovery Test', 'region' => 'Test', 'year' => 2026, 'kind' => 'kcda', 'pdf' => $this->pdfFile($count)])->assertSessionHasNoErrors();
        $p = Publication::firstOrFail();
        $c = AiProviderConfig::create(['base_url' => 'https://9router.com/v1', 'api_key' => 'test', 'model' => 'vision', 'vision_tested_at' => now(), 'created_by' => $u->id]);
        $p->update(['ai_config_id' => $c->id, 'status' => 'partial_failure']);

        return $p;
    }

    public function test_179_of_180_pages_never_complete_and_resume_skips_completed_pages(): void
    {
        $p = $this->pub(180);
        $p->pages()->where('physical_page', '<', 180)->update(['status' => 'complete', 'result' => json_encode($this->pageResult()), 'completed_at' => now()]);
        $this->assertFalse(app(AuditCoordinator::class)->canComplete($p));
        app(AuditCoordinator::class)->start($p);
        Queue::assertPushed(RenderPage::class, 1);
        $this->assertSame(179, $p->pages()->where('status', 'complete')->count());
    }

    public function test_complete_flag_without_result_is_not_sufficient(): void
    {
        $p = $this->pub(3);
        $p->pages()->update(['status' => 'complete']);
        $this->assertFalse(app(AuditCoordinator::class)->canComplete($p));
    }

    public function test_after_page_keeps_processing_status_while_remaining_pages_are_active(): void
    {
        $p = $this->pub(5);
        $p->update(['status' => 'processing']);
        // Page 1 is failed, Page 2 is complete, Pages 3, 4, 5 are still 'ready'
        $p->pages()->where('physical_page', 1)->update(['status' => 'failed']);
        $p->pages()->where('physical_page', 2)->update(['status' => 'complete', 'result' => json_encode($this->pageResult()), 'completed_at' => now()]);
        $p->pages()->whereIn('physical_page', [3, 4, 5])->update(['status' => 'ready']);

        app(AuditCoordinator::class)->afterPage($p);
        $p->refresh();

        // Must STAY 'processing' because pages 3, 4, 5 are still ready/pending!
        $this->assertSame('processing', $p->status);

        // Now suppose pages 3, 4, 5 also finish: pages 3 & 4 complete, page 5 failed
        $p->pages()->whereIn('physical_page', [3, 4])->update(['status' => 'complete', 'result' => json_encode($this->pageResult()), 'completed_at' => now()]);
        $p->pages()->where('physical_page', 5)->update(['status' => 'failed']);

        app(AuditCoordinator::class)->afterPage($p);
        $p->refresh();

        // Now that NO active pages remain, status becomes 'partial_failure' at the end of the batch!
        $this->assertSame('partial_failure', $p->status);
    }
}
