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
     *   limitations: array<int, string>,
     *   cover_page?: int,
     *   title_page?: int
     * }
     */
    public function compare(PublicationCheckRun $run, int $coverPage, int $titlePage): array
    {
        $pub = $run->publication;
        $config = \App\Models\AiAgent::ready()->first() ?? $pub->aiConfig ?? AiProviderConfig::latest('id')->first();
        $isTested = ($config instanceof \App\Models\AiAgent) ? $config->isReady() : (bool) $config?->vision_tested_at;

        if (! $config || ! $isTested) {
            return [
                'decision' => 'needs_verification',
                'components' => [],
                'findings' => [],
                'evidences' => [],
                'limitations' => ['Koneksi AI vision belum lulus uji atau belum dikonfigurasi.'],
                'cover_page' => $coverPage,
                'title_page' => $titlePage,
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
                'cover_page' => $coverPage,
                'title_page' => $titlePage,
            ];
        }

        $coverBase64 = base64_encode((string) $disk->get($coverPath));
        $titleBase64 = base64_encode((string) $disk->get($titlePath));

        $promptText = <<<PROMPT
Anda adalah auditor kendali mutu publikasi resmi BPS (Badan Pusat Statistik).
Tugas Anda: Bandingkan Gambar 1 (Kover Depan hlm. {$coverPage}) dengan Gambar 2 (Halaman Judul hlm. {$titlePage}) berdasarkan Pedoman Tata Letak Publikasi BPS.

Pemeriksaan wajib:
1. title_alignment (Kesesuaian Judul): Periksa apakah teks judul bahasa Indonesia dan bahasa Inggris persis sama antara kover depan dan halaman judul.
2. white_background (Latar Belakang Putih): Periksa apakah halaman judul berlatar belakang putih polos bersih (pedoman BPS melarang foto kover atau grafis warna pada halaman judul).
3. logo (Logo BPS): Periksa apakah logo resmi BPS tercantum dengan jelas dan proporsional pada kover dan halaman judul. CATATAN PENTING: Sesuai Pedoman BPS 2.1 butir 8, kesatuan logo dan nama BPS boleh ditempatkan di kiri, tengah, atau kanan bagian bawah kover. DILARANG menganggap penempatan logo di sudut kanan bawah sebagai kesalahan.
4. layout_harmony (Keselarasan Layout & Metadata): Periksa apakah nomor volume, tahun publikasi, nomor katalog, dan nama satker BPS konsisten antara kover depan dan halaman judul.

Format output WAJIB JSON murni tanpa markdown/penjelasan pembuka/penutup dengan struktur berikut:
{
  "readability": "readable",
  "components": {
    "title_alignment": {
      "decision": "conform",
      "observed_title": "Deskripsi pengamatan judul kover vs judul halaman dalam"
    },
    "white_background": {
      "decision": "conform",
      "observed_title": "Deskripsi latar belakang halaman judul putih polos"
    },
    "logo": {
      "decision": "conform",
      "observed_title": "Logo BPS pada kover dan judul"
    },
    "layout_harmony": {
      "decision": "conform",
      "observed_title": "Nomor volume, tahun, dan satker"
    }
  },
  "findings": []
}
Catatan: Jika ada ketidaksesuaian (misal nomor volume berbeda, judul tidak cocok, halaman judul bukan berlatar putih polos, dsb), ubah decision komponen terkait menjadi "nonconform" dan buat butir temuan di "findings" dengan severity "wajib", rule_code "TITLE.COVER_LAYOUT" atau "TITLE.COVER_MATCH", observed, expected, recommendation, cover_quote, title_quote. JANGAN membuat temuan terkait posisi sudut logo di kanan bawah.
PROMPT;

        $examinerLearnings = app(ExaminerLearningService::class)->activeLearningsForPrompt();
        if ($examinerLearnings !== '') {
            $promptText .= "\n".$examinerLearnings;
        }

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
                'cover_page' => $coverPage,
                'title_page' => $titlePage,
            ];
        }

        $result = $response['result'] ?? [];

        // Normalize parsing: handle both structured and free-form Indonesian responses
        $components = [];
        $findings = [];
        $isReadable = ($result['readability'] ?? null) === 'readable';

        // Check structured components
        if (isset($result['components']) && is_array($result['components'])) {
            $isReadable = true;
            foreach (['title_alignment', 'white_background', 'logo', 'layout_harmony'] as $k) {
                if (isset($result['components'][$k])) {
                    $item = $result['components'][$k];
                    $components[$k] = [
                        'decision' => in_array($item['decision'] ?? '', ['conform', 'nonconform', 'needs_verification'], true) ? $item['decision'] : 'conform',
                        'observed_title' => (string) ($item['observed_title'] ?? $item['observed'] ?? ''),
                    ];
                }
            }
        }

        // Check raw perbandingan structure as fallback (e.g. from freeform output)
        if (isset($result['perbandingan']) && is_array($result['perbandingan'])) {
            $isReadable = true;
            $p = $result['perbandingan'];

            // Judul
            $judulMatch = ! str_contains(strtolower((string) ($p['judul_publikasi']['status'] ?? '')), 'tidak') && ! str_contains(strtolower((string) ($p['judul_publikasi']['status'] ?? '')), 'beda');
            $components['title_alignment'] = [
                'decision' => $judulMatch ? 'conform' : 'nonconform',
                'observed_title' => (string) ($p['judul_publikasi']['keterangan'] ?? 'Judul kover dan halaman dalam'),
            ];

            // Logo
            $logoMatch = ! str_contains(strtolower((string) ($p['identitas_penerbit_dan_logo']['status'] ?? '')), 'tidak');
            $components['logo'] = [
                'decision' => $logoMatch ? 'conform' : 'nonconform',
                'observed_title' => (string) ($p['identitas_penerbit_dan_logo']['keterangan'] ?? 'Logo dan identitas satker BPS'),
            ];

            // White Background
            $components['white_background'] = [
                'decision' => 'conform',
                'observed_title' => 'Latar belakang halaman judul putih bersih sesuai pedoman',
            ];

            // Layout Harmony (Volume, Year)
            $volStatus = strtolower((string) ($p['nomor_volume']['status'] ?? ''));
            $volMatch = ! str_contains($volStatus, 'tidak') && ! str_contains($volStatus, 'inkonsisten') && ! str_contains($volStatus, 'perbedaan');
            $components['layout_harmony'] = [
                'decision' => $volMatch ? 'conform' : 'nonconform',
                'observed_title' => (string) ($p['nomor_volume']['keterangan'] ?? 'Metadata nomor volume dan tahun'),
            ];

            if (! $volMatch) {
                $findings[] = [
                    'rule_code' => 'TITLE.COVER_MATCH',
                    'section' => 'Perbandingan visual kover dan judul',
                    'severity' => 'wajib',
                    'source' => 'ai_visual',
                    'confidence' => 0.95,
                    'observed' => (string) ($p['nomor_volume']['keterangan'] ?? 'Terdapat perbedaan nomor volume antara kover depan dan halaman judul.'),
                    'expected' => 'Nomor volume publikasi pada kover depan dan halaman judul harus konsisten.',
                    'recommendation' => 'Sesuaikan nomor volume agar bernilai sama antara kover depan dan halaman judul.',
                    'evidences' => [
                        ['physical_page' => $coverPage, 'quote' => (string) ($p['nomor_volume']['kover_depan'] ?? 'Kover depan')],
                        ['physical_page' => $titlePage, 'quote' => (string) ($p['nomor_volume']['halaman_judul'] ?? 'Halaman judul')],
                    ],
                ];
            }
        }

        // Process findings array if present
        $rawFindings = $result['findings'] ?? [];
        if (is_array($rawFindings)) {
            foreach ($rawFindings as $f) {
                $isReadable = true;
                $findings[] = [
                    'rule_code' => $f['rule_code'] ?? 'TITLE.COVER_LAYOUT',
                    'section' => 'Perbandingan visual kover dan judul',
                    'severity' => $f['severity'] ?? 'wajib',
                    'source' => 'ai_visual',
                    'confidence' => 0.92,
                    'observed' => (string) ($f['observed'] ?? ''),
                    'expected' => (string) ($f['expected'] ?? ''),
                    'recommendation' => (string) ($f['recommendation'] ?? ''),
                    'evidences' => [
                        ['physical_page' => $coverPage, 'quote' => (string) ($f['cover_quote'] ?? 'Kover depan')],
                        ['physical_page' => $titlePage, 'quote' => (string) ($f['title_quote'] ?? 'Halaman judul')],
                    ],
                ];
            }
        }

        // Check if critical notes contain findings
        if (isset($result['catatan_kritis']) && is_array($result['catatan_kritis']) && empty($findings)) {
            foreach ($result['catatan_kritis'] as $note) {
                if (is_string($note) && ! empty(trim($note))) {
                    $isReadable = true;
                    $findings[] = [
                        'rule_code' => 'TITLE.COVER_LAYOUT',
                        'section' => 'Perbandingan visual kover dan judul',
                        'severity' => 'wajib',
                        'source' => 'ai_visual',
                        'confidence' => 0.90,
                        'observed' => $note,
                        'expected' => 'Kover depan dan halaman judul harus selaras sesuai pedoman BPS.',
                        'recommendation' => 'Periksa dan sesuaikan ketidaksesuaian yang tercantum pada catatan.',
                        'evidences' => [
                            ['physical_page' => $coverPage, 'quote' => 'Kover depan'],
                            ['physical_page' => $titlePage, 'quote' => 'Halaman judul'],
                        ],
                    ];
                }
            }
        }

        // If readability failed and no components were parsed
        if (! $isReadable || empty($components)) {
            return [
                'decision' => 'needs_verification',
                'components' => $components,
                'findings' => [],
                'evidences' => [],
                'limitations' => ['AI belum dapat memverifikasi keselarasan visual kover-judul dengan yakin (keterbacaan: '.($result['readability'] ?? 'unreadable').').'],
                'cover_page' => $coverPage,
                'title_page' => $titlePage,
            ];
        }

        // Decision logic: nonconform if findings exist or any component is nonconform
        $hasNonconformComponent = false;
        foreach ($components as $c) {
            if (($c['decision'] ?? '') === 'nonconform') {
                $hasNonconformComponent = true;
                break;
            }
        }

        $decision = (! empty($findings) || $hasNonconformComponent) ? 'nonconform' : 'conform';

        return [
            'decision' => $decision,
            'components' => $components,
            'findings' => $findings,
            'evidences' => [
                ['physical_page' => $coverPage, 'quote' => 'Gambar kover depan'],
                ['physical_page' => $titlePage, 'quote' => 'Gambar halaman judul'],
            ],
            'limitations' => [],
            'cover_page' => $coverPage,
            'title_page' => $titlePage,
        ];
    }
}
