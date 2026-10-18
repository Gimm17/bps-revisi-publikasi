<?php

declare(strict_types=1);

namespace App\Audit;

use App\Models\ReferenceProviderConfig;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BraveSearchClient implements PublicationSearchClient
{
    public function __construct(
        private ?ReferenceProviderConfig $config = null,
        private ?ReferenceUrlPolicy $policy = null
    ) {
        $this->policy ??= new ReferenceUrlPolicy;
    }

    public function search(string $query, int $limit = 5): array
    {
        $config = $this->config ?? ReferenceProviderConfig::where('provider', 'brave_search')->where('is_ready', true)->latest('id')->first();
        if (! $config || ! $config->api_key) {
            return [];
        }

        $endpoint = rtrim($config->endpoint ?: 'https://api.search.brave.com/res/v1/web/search', '/');

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'X-Subscription-Token' => $config->api_key,
            ])->timeout(15)->get($endpoint, [
                'q' => $query,
                'count' => min(10, max(1, $limit)),
                'search_lang' => 'id',
                'country' => 'id',
            ]);
        } catch (\Throwable $e) {
            throw new RuntimeException('Koneksi layanan pencarian gagal: '.$this->policy->sanitize($e->getMessage()));
        }

        if (! $response->successful()) {
            throw new RuntimeException('Layanan pencarian mengembalikan status HTTP '.$response->status());
        }

        $results = [];
        $rawResults = $response->json('web.results') ?? [];
        $rank = 1;

        foreach ($rawResults as $item) {
            $url = $item['url'] ?? '';
            if (! is_string($url) || ! $this->policy->allows($url)) {
                continue;
            }

            $results[] = [
                'rank' => $rank++,
                'title' => (string) ($item['title'] ?? ''),
                'url' => $url,
                'snippet' => (string) ($item['description'] ?? ''),
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }
}
