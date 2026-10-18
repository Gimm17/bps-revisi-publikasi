<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\BrowserlessPublicationClient;
use App\Audit\ReferenceUrlPolicy;
use App\Models\ReferenceProviderConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReferenceProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_redirect_private_host_is_rejected(): void
    {
        $urlPolicy = new ReferenceUrlPolicy;

        $this->assertTrue($urlPolicy->allows('https://sulut.bps.go.id/publication/view/123'));
        $this->assertTrue($urlPolicy->allows('https://bps.go.id/id/publication/456'));
        $this->assertFalse($urlPolicy->allows('https://bps.go.id.attacker.invalid/publication'));
        $this->assertFalse($urlPolicy->allows('https://attacker.bps.go.id.evil.com/'));
        $this->assertFalse($urlPolicy->allows('http://sulut.bps.go.id/')); // Non-HTTPS
        $this->assertFalse($urlPolicy->allows('https://192.168.1.1/'));
        $this->assertFalse($urlPolicy->allows('https://127.0.0.1:8000/'));
        $this->assertFalse($urlPolicy->allows('https://user:pass@sulut.bps.go.id/'));
    }

    public function test_provider_error_redacts_query_token(): void
    {
        $urlPolicy = new ReferenceUrlPolicy;
        $secretToken = 'secret-token-xyz-12345';
        $rawError = "Connection error to https://chrome.browserless.io/content?token={$secretToken}&stealth=true";

        $sanitizedError = $urlPolicy->sanitize($rawError);

        $this->assertStringNotContainsString($secretToken, $sanitizedError);
        $this->assertStringContainsString('token=[REDACTED]', $sanitizedError);
    }

    public function test_browser_access_success_does_not_imply_read_success(): void
    {
        $secretToken = 'browserless-token-999';
        $config = ReferenceProviderConfig::create([
            'provider' => 'browserless',
            'endpoint' => 'https://chrome.browserless.io',
            'api_key' => $secretToken,
            'is_ready' => true,
            'created_by' => User::factory()->create()->id,
        ]);

        Http::fake([
            'chrome.browserless.io/*' => Http::response([
                'html' => '<html><head><title>Publikasi BPS</title></head><body><div>Ringkasan publikasi tanpa data volume</div></body></html>',
                'screenshot' => '',
            ], 200),
        ]);

        $client = new BrowserlessPublicationClient(new ReferenceUrlPolicy, $config);
        $inspection = $client->inspect('https://sulut.bps.go.id/publication/view/test');

        $this->assertSame('opened', $inspection['access_status']);
        $this->assertSame('unreadable', $inspection['read_status']);
        $this->assertSame('volume_not_found', $inspection['error_code']);
    }

    public function test_browser_captcha_is_marked_as_blocked(): void
    {
        $config = ReferenceProviderConfig::create([
            'provider' => 'browserless',
            'endpoint' => 'https://chrome.browserless.io',
            'api_key' => 'token-123',
            'is_ready' => true,
            'created_by' => User::factory()->create()->id,
        ]);

        Http::fake([
            'chrome.browserless.io/*' => Http::response([
                'html' => '<html><body><div>Please complete the security check captcha to continue</div></body></html>',
            ], 200),
        ]);

        $client = new BrowserlessPublicationClient(new ReferenceUrlPolicy, $config);
        $inspection = $client->inspect('https://sulut.bps.go.id/publication/view/test');

        $this->assertSame('blocked', $inspection['access_status']);
        $this->assertSame('captcha_or_blocked', $inspection['error_code']);
    }
}
