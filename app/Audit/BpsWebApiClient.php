<?php

declare(strict_types=1);

namespace App\Audit;

use App\Models\AiAgent;
use App\Models\AiProviderConfig;
use App\Models\Publication;
use App\Models\PublicationReference;
use App\Models\ReferenceProviderConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BpsWebApiClient
{
    private const DEFAULT_KEY = '48d12534ec7c655e55239dde51e54869be3413d86dfc5ae1076e89258a4fc8ff';
    private const BASE_URL = 'https://webapi.bps.go.id/v1/api';

    public function __construct(
        private ?NineRouterClient $aiClient = null,
        private ?PdfPreparer $pdfPreparer = null
    ) {
        $this->aiClient ??= app(NineRouterClient::class);
        $this->pdfPreparer ??= app(PdfPreparer::class);
    }

    public function getApiKey(): string
    {
        try {
            $config = ReferenceProviderConfig::where('provider', 'bps_webapi')
                ->where('is_ready', true)
                ->latest('id')
                ->first();
            if ($config && ! empty($config->api_key)) {
                return trim((string) $config->api_key);
            }
        } catch (\Throwable) {
            // Ignore DB errors and fallback to default key
        }

        return (string) config('audit.bps_webapi_key', self::DEFAULT_KEY);
    }

    /**
     * Extract current publication's volume mentions across cover, title, catalogue, and team pages,
     * as well as the 4-digit BPS domain ID and clean region name.
     *
     * @return array{
     *   observed_volumes: array<string, ?int>,
     *   page_map: array<string, int>,
     *   domain_id: ?string,
     *   catalogue_number: ?string,
     *   publication_number: ?string,
     *   clean_region: string,
     *   year: int
     * }
     */
    public function extractCurrentPublicationIdentity(Publication $pub): array
    {
        $pages = $pub->pages()
            ->where('physical_page', '<=', 8)
            ->orderBy('physical_page')
            ->get();

        $observedVolumes = [];
        $pageMap = [];
        $domainId = null;
        $catalogueNumber = null;
        $publicationNumber = null;
        $publisherTexts = [];

        $pdfFullPath = $pub->pdf_path && Storage::disk('local')->exists($pub->pdf_path)
            ? Storage::disk('local')->path($pub->pdf_path)
            : null;

        foreach ($pages as $page) {
            $pNum = (int) $page->physical_page;
            $pType = (string) ($page->page_type ?? '');

            $text = (string) ($page->text_layer ?: ($page->machine_result['text_preview'] ?? ($page->result['text'] ?? '')));
            if (trim($text) === '' && $pdfFullPath && $pNum <= 6) {
                try {
                    $text = $this->pdfPreparer->extractText($pdfFullPath, $pNum);
                } catch (\Throwable) {
                    $text = '';
                }
            }

            $identityVol = $page->result['identity']['volume'] ?? null;
            $volInt = $this->parseVolumeNumber((string) $identityVol) ?? $this->parseVolumeNumber($text);

            // Detect part role (cover, title, catalogue, team)
            $part = null;
            if ($pNum === 1 || $pType === 'front_cover') {
                $part = 'cover';
            } elseif ($pType === 'title' || ($pNum <= 3 && ! isset($observedVolumes['title']) && preg_match('/DALAM\s+ANGKA/iu', $text) && ! preg_match('/Ukuran\s+Buku|Book\s+Size|Penyusun\s+Naskah|Dilarang\s+mereproduksi/iu', $text))) {
                $part = 'title';
            } elseif ($pType === 'catalogue' || preg_match('/Ukuran\s+Buku|Book\s+Size|Nomor\s+Publikasi|Publication\s+Number|Dilarang\s+mereproduksi/iu', $text)) {
                $part = 'catalogue';
            } elseif ($pType === 'team' || preg_match('/TIM\s+PENYUSUN|COMPILERS|Penanggung\s+Jawab/iu', $text)) {
                $part = 'team';
            }

            if ($part !== null && ! array_key_exists($part, $observedVolumes)) {
                $observedVolumes[$part] = $volInt;
                $pageMap[$part] = $pNum;
            }

            // Extract Catalogue Number & 4-digit BPS Domain ID (e.g. 1102001.7209020 -> 7209)
            $idCat = (string) ($page->result['identity']['catalogue'] ?? '');
            $combinedCatSource = $idCat."\n".$text;
            if ($domainId === null && preg_match('/\b11\d{5}\.(\d{4})\d{2,4}\b/u', $combinedCatSource, $cm)) {
                $domainId = $cm[1];
                $catalogueNumber = $cm[0];
            }

            // Extract Publication Number & 4-digit BPS Domain ID (e.g. 72090.26007 -> 7209)
            $idPubNo = (string) ($page->result['identity']['publication_number'] ?? '');
            $combinedPubSource = $idPubNo."\n".$text;
            if ($publicationNumber === null && preg_match('/\b(\d{4})\d\.\d{2}\d{2,4}\b/u', $combinedPubSource, $pm)) {
                $publicationNumber = $pm[0];
                if ($domainId === null) {
                    $domainId = $pm[1];
                }
            }

            if (! empty($page->result['identity']['publisher'])) {
                $publisherTexts[] = (string) $page->result['identity']['publisher'];
            }
            if (preg_match('/BADAN\s+PUSAT\s+STATISTIK\s+(?:KABUPATEN\/KOTA|KABUPATEN|KOTA|PROVINSI)\s+([A-Z\s\-]+)/iu', $text, $pubM)) {
                $publisherTexts[] = trim($pubM[0]);
            }
        }

        // Ensure standard keys exist if pages were scanned
        foreach (['cover' => 1, 'title' => 3, 'catalogue' => 4] as $defaultPart => $defaultPage) {
            if (! array_key_exists($defaultPart, $observedVolumes) && $pages->count() >= $defaultPage) {
                $observedVolumes[$defaultPart] = null;
                $pageMap[$defaultPart] = $defaultPage;
            }
        }

        $cleanRegion = $this->cleanRegionName((string) ($pub->region ?: $pub->title));

        if ($domainId === null && ! empty($publisherTexts)) {
            $domainId = $this->resolveDomainFromPublisher(implode(' ', $publisherTexts));
        }

        return [
            'observed_volumes' => $observedVolumes,
            'page_map' => $pageMap,
            'domain_id' => $domainId,
            'catalogue_number' => $catalogueNumber,
            'publication_number' => $publicationNumber,
            'clean_region' => $cleanRegion,
            'year' => (int) ($pub->year ?: 2026),
        ];
    }

    public function parseVolumeNumber(string $text): ?int
    {
        if (trim($text) === '') {
            return null;
        }

        // If string is just a number (like "12" or "32")
        if (preg_match('/^\s*(\d{1,3})\s*$/', $text, $m)) {
            $val = (int) $m[1];

            return ($val >= 1 && $val <= 200) ? $val : null;
        }

        // Match "Volume 32, 2026" or "Vol. 12" or "Volume: 15"
        if (preg_match('/(?:Volume|Vol\.?|Jilid)\s*[:\.]?\s*(\d{1,3})(?!\s*(?:Air|Sampah|Lalu|Perdagangan|Produksi|Ekspor|Impor|Kendaraan|Kayu|Ikan|Tambang|Bongkar|Muat))/iu', $text, $m)) {
            $val = (int) $m[1];

            return ($val >= 1 && $val <= 200) ? $val : null;
        }

        return null;
    }

    public function cleanRegionName(string $raw): string
    {
        $clean = trim($raw);
        // Strip "Kecamatan ... Dalam Angka 2026"
        $clean = preg_replace('/\bDalam\s+Angka\b.*$/iu', '', $clean) ?? $clean;
        $clean = preg_replace('/\b(?:19|20)\d{2}\b/u', '', $clean) ?? $clean;
        $clean = preg_replace('/\bv\d+\b/iu', '', $clean) ?? $clean;
        $clean = preg_replace('/^\s*(?:Kecamatan|Kabupaten|Kota|Provinsi)\s+/iu', '', $clean) ?? $clean;

        return trim($clean);
    }

    /**
     * Resolve prior publication reference via Local Memory -> BPS WebAPI -> Cover Vision / PDF Text.
     *
     * @param  array<string, mixed>  $identity
     * @return array<string, mixed>|null
     */
    public function resolvePriorReference(Publication $pub, array $identity): ?array
    {
        $cleanRegion = $identity['clean_region'] ?? $this->cleanRegionName((string) $pub->region);
        $currentYear = (int) ($identity['year'] ?? $pub->year ?: 2026);
        $priorYear = $currentYear - 1;
        $domainId = $identity['domain_id'] ?? null;

        // 1. Check Local Memory (previously verified PublicationReference for the same jurisdiction)
        $cachedRef = PublicationReference::where('read_status', 'readable')
            ->where(function ($q) use ($cleanRegion, $pub) {
                $q->whereRaw('LOWER(jurisdiction) = ?', [mb_strtolower($cleanRegion)])
                    ->orWhereRaw('LOWER(jurisdiction) = ?', [mb_strtolower((string) $pub->region)])
                    ->orWhereRaw('LOWER(jurisdiction) = ?', ['kecamatan '.mb_strtolower($cleanRegion)]);
            })
            ->where('year', '<=', $priorYear)
            ->orderByDesc('year')
            ->orderByDesc('id')
            ->first();

        if ($cachedRef && isset($cachedRef->metadata['volume']) && is_numeric($cachedRef->metadata['volume'])) {
            $refVol = (int) $cachedRef->metadata['volume'];
            $refYear = (int) ($cachedRef->year ?: $priorYear);
            $adjustedPriorVol = $refVol + ($priorYear - $refYear);

            return [
                'series' => $pub->kind,
                'jurisdiction' => $cleanRegion,
                'year' => $priorYear,
                'source_url' => $cachedRef->source_url,
                'provenance' => $cachedRef->provenance ?: 'local_memory',
                'access_status' => 'opened',
                'read_status' => 'readable',
                'metadata' => array_merge($cachedRef->metadata ?? [], [
                    'volume' => $adjustedPriorVol,
                    'raw_reference_volume' => $refVol,
                    'raw_reference_year' => $refYear,
                    'year' => $priorYear,
                    'domain_id' => $domainId ?? ($cachedRef->metadata['domain_id'] ?? null),
                ]),
                'artifact_manifest' => $cachedRef->artifact_manifest ?? [],
            ];
        }

        // 2. Query Official BPS WebAPI
        $candidateDomains = $domainId ? [$domainId] : ['7209', '7202', '7271', '7201', '7203', '7204', '7205', '7206', '7207', '7208', '7210', '7211', '7212', '7200'];
        $matchedItem = null;
        $matchedDomain = null;
        $allSeriesEditions = [];

        foreach ($candidateDomains as $dom) {
            $editions = $this->searchSeriesInDomain($dom, $cleanRegion);
            if (! empty($editions)) {
                $matchedDomain = $dom;
                $allSeriesEditions = $editions;
                break;
            }
        }

        if (empty($allSeriesEditions) || $matchedDomain === null) {
            return null;
        }

        // Prefer exact prior year ($currentYear - 1), otherwise closest year < $currentYear
        foreach ($allSeriesEditions as $ed) {
            if ($ed['extracted_year'] === $priorYear) {
                $matchedItem = $ed;
                break;
            }
        }
        if (! $matchedItem) {
            foreach ($allSeriesEditions as $ed) {
                if ($ed['extracted_year'] < $currentYear) {
                    $matchedItem = $ed;
                    break;
                }
            }
        }
        if (! $matchedItem) {
            $matchedItem = $allSeriesEditions[0];
        }

        $refYear = (int) ($matchedItem['extracted_year'] ?: $priorYear);
        $pubId = (string) $matchedItem['pub_id'];

        // Fetch full detail from BPS WebAPI view endpoint
        $detail = $this->fetchPublicationDetail($matchedDomain, $pubId) ?? $matchedItem;

        // If initial match is older than $priorYear, check $detail['related'] for the exact $priorYear edition!
        if ($refYear < $priorYear && ! empty($detail['related']) && is_array($detail['related'])) {
            $escapedRegion = preg_quote($cleanRegion, '/');
            $escapedRegion = str_replace('\ ', '[\s\-]+', $escapedRegion);
            $relPattern = '/(?:Kecamatan\s+|Kabupaten\s+|Kota\s+|Provinsi\s+|^)'.$escapedRegion.'\s+Dalam\s+Angka\s+'.$priorYear.'/iu';
            foreach ($detail['related'] as $relItem) {
                $relTitle = trim((string) ($relItem['title'] ?? ''));
                $relPubId = (string) ($relItem['pub_id'] ?? '');
                if ($relPubId !== '' && preg_match($relPattern, $relTitle)) {
                    $upgradedDetail = $this->fetchPublicationDetail($matchedDomain, $relPubId);
                    if ($upgradedDetail) {
                        $detail = $upgradedDetail;
                        $matchedItem = $upgradedDetail;
                        $pubId = $relPubId;
                        $refYear = $priorYear;
                        break;
                    }
                }
            }
        }

        $coverUrl = (string) ($detail['cover'] ?? $matchedItem['cover'] ?? '');
        $pdfUrl = (string) ($detail['pdf'] ?? $matchedItem['pdf'] ?? '');
        $webUrl = $this->findWebUrlFromRelated($detail, $pubId) ?: "https://webapi.bps.go.id/v1/api/view/model/publication/domain/{$matchedDomain}/id/{$pubId}";

        $artifacts = [];
        $localCoverRelPath = null;

        // Download cover image (~50-200 KB)
        if ($coverUrl !== '') {
            try {
                $imgResp = Http::withoutVerifying()->timeout(20)->get($coverUrl);
                if ($imgResp->successful() && strlen($imgResp->body()) > 1000) {
                    $localCoverRelPath = "publications/{$pub->id}/references/bps_cover_{$refYear}_{$pubId}.jpg";
                    Storage::disk('local')->put($localCoverRelPath, $imgResp->body());
                    $artifacts[] = [
                        'id' => 'cover_image',
                        'artifact_type' => 'cover_image',
                        'path' => $localCoverRelPath,
                        'source_url' => $coverUrl,
                        'page' => null,
                        'quote' => (string) ($detail['title'] ?? "Sampul Edisi {$refYear}"),
                        'checksum' => hash('sha256', $imgResp->body()),
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning('BpsWebApiClient: Failed downloading cover image: '.$e->getMessage());
            }
        }

        // Step A: Read volume from downloaded cover image via Vision AI
        $detectedVolume = null;
        $volumeSource = null;

        if ($localCoverRelPath && Storage::disk('local')->exists($localCoverRelPath)) {
            $visionData = $this->readVolumeFromCoverImage(Storage::disk('local')->path($localCoverRelPath), $refYear);
            if (isset($visionData['volume']) && is_numeric($visionData['volume'])) {
                $detectedVolume = (int) $visionData['volume'];
                $volumeSource = 'bps_webapi_cover_vision';
            }
        }

        // Step B: If cover did not contain volume number, try extracting text from pages 1-6 of the prior PDF
        if ($detectedVolume === null && $pdfUrl !== '') {
            $pdfVol = $this->extractVolumeFromRemotePdf($pdfUrl);
            if ($pdfVol !== null) {
                $detectedVolume = $pdfVol;
                $volumeSource = 'bps_webapi_pdf_text';
            }
        }

        // Step C: If priorYear edition still has no volume printed on it, check 1-2 older editions in the same series
        if ($detectedVolume === null && count($allSeriesEditions) > 1) {
            foreach ($allSeriesEditions as $altEd) {
                $altYear = (int) ($altEd['extracted_year'] ?? 0);
                if ($altYear <= 0 || $altYear >= $currentYear || $altEd['pub_id'] === $pubId) {
                    continue;
                }
                $altCoverUrl = (string) ($altEd['cover'] ?? '');
                if ($altCoverUrl === '') {
                    continue;
                }
                try {
                    $altResp = Http::withoutVerifying()->timeout(15)->get($altCoverUrl);
                    if ($altResp->successful() && strlen($altResp->body()) > 1000) {
                        $tmpCover = sys_get_temp_dir().'/bps_alt_cover_'.$altEd['pub_id'].'.jpg';
                        file_put_contents($tmpCover, $altResp->body());
                        $altVision = $this->readVolumeFromCoverImage($tmpCover, $altYear);
                        @unlink($tmpCover);
                        if (isset($altVision['volume']) && is_numeric($altVision['volume'])) {
                            $rawVol = (int) $altVision['volume'];
                            $detectedVolume = $rawVol + ($refYear - $altYear);
                            $volumeSource = "bps_webapi_historical_{$altYear}_cover";
                            break;
                        }
                    }
                } catch (\Throwable) {
                    continue;
                }
                break; // Only try 1 fallback edition to keep execution fast
            }
        }

        // Adjust volume if matched reference year is older than $priorYear
        $finalPriorVolume = $detectedVolume !== null
            ? $detectedVolume + ($priorYear - $refYear)
            : null;

        return [
            'series' => $pub->kind,
            'jurisdiction' => $cleanRegion,
            'year' => $priorYear,
            'source_url' => $webUrl,
            'provenance' => 'bps_webapi',
            'access_status' => 'opened',
            'read_status' => $finalPriorVolume !== null ? 'readable' : 'unreadable',
            'metadata' => [
                'pub_id' => $pubId,
                'domain_id' => $matchedDomain,
                'title' => (string) ($detail['title'] ?? $matchedItem['title'] ?? ''),
                'catalogue_number' => (string) ($detail['kat_no'] ?? ''),
                'publication_number' => (string) ($detail['pub_no'] ?? ''),
                'issn' => (string) ($detail['issn'] ?? ''),
                'release_date' => (string) ($detail['rl_date'] ?? ''),
                'year' => $priorYear,
                'reference_edition_year' => $refYear,
                'volume' => $finalPriorVolume,
                'volume_source' => $volumeSource,
                'cover_url' => $coverUrl,
                'pdf_url' => $pdfUrl,
                'series_editions_count' => count($allSeriesEditions),
            ],
            'artifact_manifest' => $artifacts,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchSeriesInDomain(string $domainId, string $cleanRegion): array
    {
        $apiKey = $this->getApiKey();
        $escapedRegion = preg_quote($cleanRegion, '/');
        $escapedRegion = str_replace('\ ', '[\s\-]+', $escapedRegion);
        $pattern = '/(?:Kecamatan\s+|Kabupaten\s+|Kota\s+|Provinsi\s+|^)'.$escapedRegion.'\s+Dalam\s+Angka\s+(20\d{2})/iu';

        $queries = [
            "Kecamatan {$cleanRegion} Dalam Angka 2025",
            "Kecamatan {$cleanRegion} Dalam Angka",
            "{$cleanRegion} Dalam Angka",
            $cleanRegion,
        ];

        $matchedById = [];
        foreach ($queries as $qText) {
            $keyword = rawurlencode($qText);
            $url = self::BASE_URL."/list/model/publication/domain/{$domainId}/keyword/{$keyword}/key/{$apiKey}/";

            try {
                $resp = Http::withoutVerifying()->timeout(15)->get($url);
                if (! $resp->successful() || $resp->json('status') !== 'OK') {
                    continue;
                }

                $items = $resp->json('data.1') ?? [];
                if (! is_array($items)) {
                    continue;
                }

                foreach ($items as $item) {
                    $title = trim((string) ($item['title'] ?? ''));
                    $pubId = (string) ($item['pub_id'] ?? '');
                    if ($pubId !== '' && preg_match($pattern, $title, $m)) {
                        $item['extracted_year'] = (int) $m[1];
                        $matchedById[$pubId] = $item;
                    }
                }

                if (! empty($matchedById)) {
                    $years = array_column(array_values($matchedById), 'extracted_year');
                    if (max($years) >= 2025) {
                        break;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("BpsWebApiClient: searchSeriesInDomain failed for {$domainId}/{$qText}: ".$e->getMessage());
            }
        }

        $matched = array_values($matchedById);
        usort($matched, fn ($a, $b) => ($b['extracted_year'] ?? 0) <=> ($a['extracted_year'] ?? 0));

        return $matched;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchPublicationDetail(string $domainId, string $pubId): ?array
    {
        $apiKey = $this->getApiKey();
        $url = self::BASE_URL."/view/model/publication/domain/{$domainId}/id/{$pubId}/key/{$apiKey}/";

        try {
            $resp = Http::withoutVerifying()->timeout(15)->get($url);
            if ($resp->successful() && $resp->json('status') === 'OK' && is_array($resp->json('data'))) {
                return $resp->json('data');
            }
        } catch (\Throwable) {
            // Ignore and fallback to list item
        }

        return null;
    }

    /**
     * Use Vision AI to read "Volume XX" from the downloaded BPS cover image.
     *
     * @return array{volume?: ?int, year?: ?int, catalogue_number?: ?string}
     */
    public function readVolumeFromCoverImage(string $imageFullPath, int $expectedYear): array
    {
        if (! is_file($imageFullPath)) {
            return [];
        }

        $agent = AiAgent::ready()->first() ?? AiProviderConfig::latest('id')->first();
        if (! $agent) {
            return [];
        }

        try {
            $base64 = base64_encode((string) file_get_contents($imageFullPath));
            $prompt = "Baca gambar sampul publikasi BPS ini. Perhatikan teks nomor Volume (contoh: 'Volume 31, {$expectedYear}' atau 'Volume 11, {$expectedYear}'). Kembalikan JSON murni saja tanpa markdown: {\"volume\": <angka_integer_atau_null>, \"year\": <angka_tahun_atau_null>, \"catalogue_number\": <string_atau_null>}. Jika tidak ada kata Volume pada sampul, isi \"volume\": null.";

            $resp = $this->aiClient->request($agent, [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $prompt],
                        ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,'.$base64]],
                    ],
                ],
            ]);

            $res = $resp['result'] ?? [];
            $vol = isset($res['volume']) && is_numeric($res['volume']) ? (int) $res['volume'] : null;

            return [
                'volume' => ($vol !== null && $vol >= 1 && $vol <= 200) ? $vol : null,
                'year' => isset($res['year']) && is_numeric($res['year']) ? (int) $res['year'] : null,
                'catalogue_number' => isset($res['catalogue_number']) ? (string) $res['catalogue_number'] : null,
            ];
        } catch (\Throwable $e) {
            Log::warning('BpsWebApiClient: readVolumeFromCoverImage failed: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Download prior PDF to a temporary file and extract text from pages 1..6 to find Volume.
     */
    public function extractVolumeFromRemotePdf(string $pdfUrl): ?int
    {
        $tmpPdf = sys_get_temp_dir().'/bps_ref_'.uniqid().'.pdf';
        try {
            $resp = Http::withoutVerifying()->timeout(25)->sink($tmpPdf)->get($pdfUrl);
            if (! $resp->successful() || ! is_file($tmpPdf) || filesize($tmpPdf) < 5000) {
                return null;
            }

            for ($p = 1; $p <= 6; $p++) {
                $txt = $this->pdfPreparer->extractText($tmpPdf, $p);
                $vol = $this->parseVolumeNumber($txt);
                if ($vol !== null) {
                    return $vol;
                }
            }
        } catch (\Throwable) {
            return null;
        } finally {
            if (is_file($tmpPdf)) {
                @unlink($tmpPdf);
            }
        }

        return null;
    }

    private function findWebUrlFromRelated(array $detail, string $pubId): ?string
    {
        foreach ($detail['related'] ?? [] as $rel) {
            if (($rel['pub_id'] ?? '') === $pubId && ! empty($rel['url'])) {
                return (string) $rel['url'];
            }
        }

        return null;
    }

    private function resolveDomainFromPublisher(string $publisherText): ?string
    {
        $domains = Cache::remember('bps_webapi_domains_v1', 86400, function () {
            try {
                $apiKey = $this->getApiKey();
                $resp = Http::withoutVerifying()->timeout(15)->get(self::BASE_URL."/domain/type/all/key/{$apiKey}/");
                if ($resp->successful() && is_array($resp->json('data.1'))) {
                    return $resp->json('data.1');
                }
            } catch (\Throwable) {
                // Ignore
            }

            return [];
        });

        $lowerPub = mb_strtolower($publisherText);
        foreach ($domains as $d) {
            $dName = mb_strtolower(trim((string) ($d['domain_name'] ?? '')));
            if ($dName !== '' && $dName !== 'pusat' && str_contains($lowerPub, $dName)) {
                return (string) $d['domain_id'];
            }
        }

        return null;
    }
}
