<?php

declare(strict_types=1);

namespace App\Audit;

use App\Models\AiProviderConfig;
use App\Models\PublicationCheckRun;
use Illuminate\Support\Facades\Storage;

class CoverTitleComparator
{
    public function __construct(private ?NineRouterClient $client = null)
    {
        $this->client ??= app(NineRouterClient::class);
    }

    /**
     * @return array{
     *   decision: 'conform'|'nonconform'|'needs_verification'|'not_applicable',
     *   components: array<string, mixed>,
     *   findings: array<int, array<string, mixed>>,
     *   evidences: array<int, array<string, mixed>>,
     *   limitations: array<int, string>
     * }
     */
    public function compare(PublicationCheckRun $run, int $coverPage, int $titlePage): array
    {
        $pub = $run->publication;
        $config = $pub->aiConfig ?? AiProviderConfig::latest('id')->first();

        if (! $config || ! $config->vision_tested_at) {
            return [
                'decision' => 'needs_verification',
                'components' => [],
                'findings' => [],
                'evidences' => [],
                'limitations' => ['Koneksi AI vision belum lulus uji atau belum dikonfigurasi.'],
            ];
        }

        $disk = Storage::disk('local');
        $coverPath = "publications/{$pub->id}/pages/{$coverPage}-160.jpg";
        $titlePath = "publications/{$pub->id}/pages/{$titlePage}-160.jpg";

        if (! $disk->exists($coverPath) || ! $disk->exists($titlePath)) {
            return [
                'decision' => 'needs_verification',
                'components' => [],
                'findings' => [],
                'evidences' => [],
                'limitations' => ["Berkas gambar kover (hlm. {$coverPage}) atau judul (hlm. {$titlePage}) belum tersedia."],
            ];
        }

        $coverBase64 = base64_encode((string) $disk->get($coverPath));
        $titleBase64 = base64_encode((string) $disk->get($titlePath));

        $promptText = "Bandingkan Gambar 1 (Kover Depan hlm. {$coverPage}) dengan Gambar 2 (Halaman Judul hlm. {$titlePage}) sesuai pedoman visual BPS. Kembalikan JSON saja.";

        $messages = [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $promptText],
                    [
                        'type' => 'image_url',
                        'image_url' => ['url' => 'data:image/jpeg;base64,'.$coverBase64],
                    ],
                    [
                        'type' => 'image_url',
                        'image_url' => ['url' => 'data:image/jpeg;base64,'.$titleBase64],
                    ],
                ],
            ],
        ];

        try {
            $response = $this->client->request($config, $messages);
        } catch (\Throwable $e) {
            return [
                'decision' => 'needs_verification',
                'components' => [],
                'findings' => [],
                'evidences' => [],
                'limitations' => ['Pemeriksaan perbandingan visual AI gagal: '.$e->getMessage()],
            ];
        }

        $result = $response['result'] ?? [];
        $readability = $result['readability'] ?? 'unreadable';

        if ($readability !== 'readable') {
            return [
                'decision' => 'needs_verification',
                'components' => $result['components'] ?? [],
                'findings' => [],
                'evidences' => [],
                'limitations' => ['AI belum dapat memverifikasi keselarasan visual kover-judul dengan yakin (keterbacaan: '.$readability.').'],
            ];
        }

        $components = $result['components'] ?? [];
        $findings = [];
        $rawFindings = $result['findings'] ?? [];

        foreach ($rawFindings as $f) {
            $findings[] = [
                'rule_code' => $f['rule_code'] ?? 'TITLE.COVER_LAYOUT',
                'section' => 'Perbandingan visual kover dan judul',
                'severity' => $f['severity'] ?? 'wajib',
                'source' => 'ai_visual',
                'confidence' => 0.90,
                'observed' => $f['observed'] ?? '',
                'expected' => $f['expected'] ?? '',
                'recommendation' => $f['recommendation'] ?? '',
                'evidences' => [
                    ['physical_page' => $coverPage, 'quote' => $f['cover_quote'] ?? 'Kover depan'],
                    ['physical_page' => $titlePage, 'quote' => $f['title_quote'] ?? 'Halaman judul'],
                ],
            ];
        }

        $decision = empty($findings) ? 'conform' : 'nonconform';

        return [
            'decision' => $decision,
            'components' => $components,
            'findings' => $findings,
            'evidences' => [
                ['physical_page' => $coverPage, 'quote' => 'Gambar kover'],
                ['physical_page' => $titlePage, 'quote' => 'Gambar judul'],
            ],
            'limitations' => [],
        ];
    }
}
