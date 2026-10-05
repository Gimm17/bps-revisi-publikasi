<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RenderPage;
use App\Models\AiProviderConfig;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PublicationFixtures;
use Tests\TestCase;

class WorkflowTest extends TestCase
{
    use PublicationFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Queue::fake();
    }

    private function upload(User $user): Publication
    {
        $this->actingAs($user)->post('/publications', [
            'title' => 'Kecamatan Ampana Kota Dalam Angka 2026', 'region' => 'Ampana Kota', 'year' => 2026,
            'kind' => 'kcda', 'pdf' => $this->pdfFile(3),
        ])->assertSessionHasNoErrors()->assertRedirect();

        return Publication::firstOrFail();
    }

    public function test_upload_builds_a_manifest_but_does_not_claim_pages_are_checked(): void
    {
        $pub = $this->upload(User::factory()->create());
        $this->assertSame(3, $pub->page_count);
        $this->assertSame(3, $pub->pages()->count());
        $this->assertSame(0, $pub->pages()->where('status', 'complete')->count());
        $this->assertSame('draft', $pub->status);
        Storage::disk('local')->assertExists($pub->pdf_path);
    }

    public function test_other_employee_cannot_download_pdf(): void
    {
        $pub = $this->upload(User::factory()->create());
        $this->actingAs(User::factory()->create())->get('/publications/'.$pub->id.'/pdf')->assertForbidden();
    }

    public function test_fake_pdf_extension_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())->post('/publications', ['title' => 'Test', 'region' => 'Test', 'year' => 2026, 'kind' => 'kcda', 'pdf' => UploadedFile::fake()->createWithContent('fake.pdf', 'not a pdf')])->assertSessionHasErrors('pdf');
        $this->assertDatabaseCount('publications', 0);
    }

    public function test_audit_cannot_start_without_a_successful_vision_test(): void
    {
        $user = User::factory()->create();
        $pub = $this->upload($user);
        AiProviderConfig::create(['base_url' => 'https://9router.com/v1', 'api_key' => 'secret-key', 'model' => 'vision', 'created_by' => $user->id]);
        $this->post('/publications/'.$pub->id.'/start')->assertSessionHasErrors('ai');
        Queue::assertNotPushed(RenderPage::class);
    }

    public function test_export_is_blocked_if_even_one_page_is_unfinished(): void
    {
        $pub = $this->upload(User::factory()->create());
        $pub->pages()->where('physical_page', '<', 3)->update(['status' => 'complete']);
        $this->post('/publications/'.$pub->id.'/exports')->assertSessionHasErrors('export');
        $this->assertDatabaseCount('exports', 0);
    }

    public function test_only_admin_can_save_ai_key_and_key_is_not_returned(): void
    {
        $this->actingAs(User::factory()->create())->post('/settings/ai', ['base_url' => 'https://9router.com/v1', 'api_key' => 'secret', 'model' => 'vision'])->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/settings/ai', ['base_url' => 'https://9router.com/v1', 'api_key' => 'secret-test-key', 'model' => 'vision'])->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('secret-test-key', AiProviderConfig::latest()->first()->toJson());
        $this->assertNotSame('secret-test-key', AiProviderConfig::latest()->first()->getRawOriginal('api_key'));
    }

    public function test_unapproved_base_url_is_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/settings/ai', ['base_url' => 'http://127.0.0.1/v1', 'api_key' => 'secret', 'model' => 'vision'])->assertSessionHasErrors('base_url');
    }
}
