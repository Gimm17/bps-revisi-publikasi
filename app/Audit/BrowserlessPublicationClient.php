<?php

declare(strict_types=1);

namespace App\Audit;

use App\Models\ReferenceProviderConfig;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class BrowserlessPublicationClient implements PublicationBrowserClient
{
    public function __construct(
        private ?ReferenceUrlPolicy $policy = null,
        private ?ReferenceProviderConfig $config = null
    ) {
        $this->policy ??= new ReferenceUrlPolicy;
    }

    public function inspect(string $url): array
    {
        if (! $this->policy->allows($url)) {
            throw new InvalidArgumentException('URL tidak diizinkan oleh kebijakan keamanan: '.$this->policy->sanitize($url));
        }

        $config = $this->config ?? ReferenceProviderConfig::where('provider', 'browserless')->where('is_ready', true)->latest('id')->first();
        if (! $config || ! $config->api_key) {
            return [
                'access_status' => 'failed',
                'read_status' => 'unreadable',
                'metadata' => [],
                'artifacts' => [],
                'error_code' => 'provider_not_configured',
                'error_message' => 'Layanan browserless belum dikonfigurasi oleh admin.',
            ];
        }

        $endpoint = rtrim($config->endpoint ?: 'https://chrome.browserless.io', '/');
        $requestUrl = $endpoint.'/content?token='.urlencode($config->api_key).'&stealth=true';

        try {
            $response = Http::timeout(30)->post($requestUrl, [
                'url' => $url,
                'waitForSelector' => 'body',
            ]);
        } catch (\Throwable $e) {
            return [
                'access_status' => 'failed',
                'read_status' => 'unreadable',
                'metadata' => [],
                'artifacts' => [],
                'error_code' => 'network_error',
                'error_message' => $this->policy->sanitize($e->getMessage()),
            ];
        }

        if (! $response->successful()) {
            return [
                'access_status' => 'failed',
                'read_status' => 'unreadable',
                'metadata' => [],
                'artifacts' => [],
                'error_code' => 'http_'.$response->status(),
                'error_message' => 'Layanan browser mengembalikan status HTTP '.$response->status(),
            ];
        }

        $html = is_array($response->json()) ? (string) ($response->json('html') ?? '') : (string) $response->body();

        // Check for CAPTCHA or blocking
        if (str_contains(strtolower($html), 'captcha') || str_contains(strtolower($html), 'cloudflare') || str_contains(strtolower($html), 'access denied')) {
            return [
                'access_status' => 'blocked',
                'read_status' => 'unreadable',
                'metadata' => [],
                'artifacts' => [],
                'error_code' => 'captcha_or_blocked',
                'error_message' => 'Laman rujukan terhalang tantangan verifikasi/CAPTCHA.',
            ];
        }

        // Extract metadata: title, volume, year, download link
        $metadata = [];
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $metadata['title'] = trim(html_entity_decode(strip_tags($m[1])));
        }

        // Look for volume pattern (e.g. Volume 31, Vol. 31, Jilid 31)
        if (preg_match('/(?:Volume|Vol\.?|Jilid)\s*([0-9]+)/i', $html, $m)) {
            $metadata['volume'] = (int) $m[1];
        }

        // Look for publication year
        if (preg_match('/\b(20[12][0-9])\b/', $metadata['title'] ?? $html, $m)) {
            $metadata['year'] = (int) $m[1];
        }

        // Look for PDF download link
        if (preg_match('/href=["\']([^"\']+\.pdf(?:\?[^"\']*)?)["\']/i', $html, $m)) {
            $metadata['download_url'] = $m[1];
        }

        $readStatus = isset($metadata['volume']) ? 'readable' : 'unreadable';

        return [
            'access_status' => 'opened',
            'read_status' => $readStatus,
            'metadata' => $metadata,
            'artifacts' => [
                'html_length' => strlen($html),
            ],
            'error_code' => $readStatus === 'unreadable' ? 'volume_not_found' : null,
            'error_message' => $readStatus === 'unreadable' ? 'Laman berhasil dibuka namun informasi volume belum ditemukan.' : null,
        ];
    }
}
