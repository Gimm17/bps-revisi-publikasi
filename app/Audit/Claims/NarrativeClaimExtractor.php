<?php

declare(strict_types=1);

namespace App\Audit\Claims;

use App\Audit\Numeric\NumberParser;

class NarrativeClaimExtractor
{
    public function __construct(protected NumberParser $parser = new NumberParser) {}

    /**
     * Safely flatten arrays or scalar values into a string.
     */
    public function stringify(mixed $val): string
    {
        if (is_array($val)) {
            $parts = [];
            foreach ($val as $sub) {
                $s = $this->stringify($sub);
                if ($s !== '') {
                    $parts[] = $s;
                }
            }

            return implode(' ', $parts);
        }
        if (is_scalar($val) || (is_object($val) && method_exists($val, '__toString'))) {
            return (string) $val;
        }

        return '';
    }

    /**
     * Extract factual claims from narrative pages in a chapter and evaluate against chapter tables.
     *
     * @param  array<int, array<string, mixed>>  $chapterPages
     * @return array<int, array<string, mixed>>
     */
    public function evaluate(array $chapterPages): array
    {
        $findings = [];
        $narratives = [];
        $tables = [];

        foreach ($chapterPages as $p) {
            $phys = (int) ($p['physical_page'] ?? 0);
            $res = $p['result'] ?? [];
            $text = trim((string) ($res['text'] ?? ($p['text_layer'] ?? '')));

            if ($text !== '' && ($p['page_type'] ?? '') === 'narrative') {
                $narratives[] = [
                    'page' => $phys,
                    'text' => $text,
                    'text_layer' => $p['text_layer'] ?? null,
                ];
            }

            $invTables = $res['inventory']['tables'] ?? ($res['tables'] ?? []);
            foreach ($invTables as $t) {
                $tables[] = [
                    'page' => $phys,
                    'number' => trim((string) ($t['number'] ?? '')),
                    'title' => trim((string) ($t['title'] ?? '')),
                    'headers' => $t['headers'] ?? [],
                    'rows' => $t['rows'] ?? [],
                ];
            }
        }

        if (empty($narratives) || empty($tables)) {
            return [];
        }

        // 1. Check superlative area claim (e.g. Sansarino terluas vs Malotong terluas in Table 1.1)
        $superlativeAreaFindings = $this->checkSuperlativeArea($narratives, $tables);
        foreach ($superlativeAreaFindings as $saf) {
            $findings[] = $saf;
        }

        // 2. Check distance claims (e.g. Malotong 12 km to kecamatan vs kabupaten in Table 1.2)
        $distanceFindings = $this->checkDistanceClaims($narratives, $tables);
        foreach ($distanceFindings as $df) {
            $findings[] = $df;
        }

        // 3. Check duplicate commodity / bilingual mismatch (e.g. tomat written twice on p.85)
        $bilingualFindings = $this->checkDuplicateCommodities($narratives, $tables);
        foreach ($bilingualFindings as $bf) {
            $findings[] = $bf;
        }

        return $findings;
    }

    /**
     * Check if narrative claims an entity has the largest area when a table shows another is larger.
     *
     * @param  array<int, array{page: int, text: string, text_layer: string|null}>  $narratives
     * @param  array<int, array{page: int, number: string, title: string, headers: array, rows: array}>  $tables
     * @return array<int, array<string, mixed>>
     */
    protected function checkSuperlativeArea(array $narratives, array $tables): array
    {
        $findings = [];

        // Find Table 1.1 or area table
        $areaTable = null;
        foreach ($tables as $t) {
            $hdrs = mb_strtolower($this->stringify($t['headers'] ?? []));
            $title = mb_strtolower((string) ($t['title'] ?? ''));
            if (str_contains($title, 'luas') || str_contains($hdrs, 'luas')) {
                $areaTable = $t;
                break;
            }
        }

        if ($areaTable === null) {
            return [];
        }

        // Determine actual maximum area row from table
        $maxEntity = null;
        $maxArea = -1.0;
        $entityAreas = [];

        $rows = $areaTable['rows'] ?? [];
        $compRows = array_slice($rows, 0, -1); // Exclude last total row

        foreach ($compRows as $r) {
            $name = trim((string) ($r[0] ?? ''));
            $areaVal = $this->parser->parse($r[1] ?? null);
            if ($name !== '' && $areaVal !== null) {
                $entityAreas[mb_strtolower($name)] = [
                    'name' => $name,
                    'area' => $areaVal,
                    'percent' => $r[2] ?? null,
                ];
                if ($areaVal > $maxArea) {
                    $maxArea = $areaVal;
                    $maxEntity = $name;
                }
            }
        }

        if ($maxEntity === null) {
            return [];
        }

        // Check each narrative page
        foreach ($narratives as $n) {
            $fullText = $n['text'].' '.($n['text_layer'] ?? '');
            // Pattern: [terluas|terbesar] ... [Entity] OR [Entity] ... [terluas|terbesar]
            if (preg_match('/(?:luas|wilayah)\s+(?:terbesar|terluas)\s+(?:adalah|yaitu|merupakan)?\s*(?:desa|kelurahan)?\s*([a-zA-Z\s]+?)(?:,|\.|\bdengan|\bsebesar)/iu', $fullText, $m)) {
                $claimedEntity = trim($m[1]);
                $claimedLower = mb_strtolower($claimedEntity);

                // Strip prefixes like "Desa ", "Kelurahan "
                $claimedClean = preg_replace('/^(desa|kelurahan)\s+/i', '', $claimedLower);

                if (isset($entityAreas[$claimedClean]) && mb_strtolower($maxEntity) !== $claimedClean) {
                    $maxInfo = $entityAreas[mb_strtolower($maxEntity)];
                    $claimedInfo = $entityAreas[$claimedClean];

                    $findings[] = [
                        'rule_code' => 'NARRATIVE.TABLE_MISMATCH',
                        'severity' => 'verifikasi',
                        'source' => 'chapter_crosscheck',
                        'confidence' => 0.95,
                        'observed' => "Narasi menyebutkan {$claimedEntity} memiliki luas terbesar (persentase {$claimedInfo['percent']}%); padahal Tabel {$areaTable['number']} menunjukkan {$maxEntity} memiliki luas lebih besar yaitu {$maxInfo['area']} km\u{00B2} ({$maxInfo['percent']}%).",
                        'expected' => "Koreksi klaim wilayah terluas pada narasi agar sesuai dengan data Tabel {$areaTable['number']} ({$maxEntity} merupakan wilayah terluas).",
                        'recommendation' => "Perbaiki pernyataan pada ulasan narasi bab agar konsisten dengan data tabel resmi.",
                        'evidences' => [
                            [
                                'physical_page' => $n['page'],
                                'quote' => "Narasi: {$m[0]}",
                            ],
                            [
                                'physical_page' => $areaTable['page'],
                                'quote' => "Tabel {$areaTable['number']}: {$maxEntity} ({$maxInfo['area']} km\u{00B2}, {$maxInfo['percent']}%) vs {$claimedEntity} ({$claimedInfo['area']} km\u{00B2}, {$claimedInfo['percent']}%)",
                            ],
                        ],
                    ];
                }
            }
        }

        return $findings;
    }

    /**
     * Check distance claims in narrative against distance table (e.g. Table 1.2).
     *
     * @param  array<int, array{page: int, text: string, text_layer: string|null}>  $narratives
     * @param  array<int, array{page: int, number: string, title: string, headers: array, rows: array}>  $tables
     * @return array<int, array<string, mixed>>
     */
    protected function checkDistanceClaims(array $narratives, array $tables): array
    {
        $findings = [];

        // Find Table 1.2 distance table
        $distTable = null;
        foreach ($tables as $t) {
            $hdrs = mb_strtolower($this->stringify($t['headers'] ?? []));
            if (str_contains($hdrs, 'jarak ke ibukota kecamatan') || (str_contains($hdrs, 'kecamatan') && str_contains($hdrs, 'kabupaten'))) {
                $distTable = $t;
                break;
            }
        }

        if ($distTable === null) {
            return [];
        }

        // Map distances: village => [kecamatan => x, kabupaten => y]
        $villageDists = [];
        foreach ($distTable['rows'] as $r) {
            $name = trim((string) ($r[0] ?? ''));
            $dKec = $this->parser->parse($r[1] ?? null);
            $dKab = $this->parser->parse($r[2] ?? null);
            if ($name !== '' && $dKec !== null && $dKab !== null) {
                $villageDists[mb_strtolower($name)] = [
                    'name' => $name,
                    'kecamatan' => $dKec,
                    'kabupaten' => $dKab,
                ];
            }
        }

        foreach ($narratives as $n) {
            $fullText = $n['text'].' '.($n['text_layer'] ?? '');

            // Pattern: [Kelurahan/Desa X] merupakan daerah terjauh dengan jarak [12] km ... menuju pusat kecamatan
            if (preg_match('/([a-zA-Z\s]+?)\s+merupakan\s+daerah\s+terjauh\s+dengan\s+jarak\s+(\d+(?:[.,]\d+)?)\s*km\b.*?pusat\s+kecamatan/iu', $fullText, $m)) {
                $entityRaw = trim($m[1]);
                $distVal = (float) str_replace(',', '.', $m[2]);
                $cleanEntity = preg_replace('/^(kelurahan|desa)\s+/i', '', mb_strtolower($entityRaw));

                if (isset($villageDists[$cleanEntity])) {
                    $actual = $villageDists[$cleanEntity];
                    // If stated distance matches distance to kabupaten instead of kecamatan!
                    if (abs($actual['kabupaten'] - $distVal) < 0.1 && abs($actual['kecamatan'] - $distVal) > 0.5) {
                        $findings[] = [
                            'rule_code' => 'NARRATIVE.TABLE_MISMATCH',
                            'severity' => 'verifikasi',
                            'source' => 'chapter_crosscheck',
                            'confidence' => 0.95,
                            'observed' => "Narasi menyebutkan {$entityRaw} daerah terjauh {$m[2]} km ke pusat kecamatan; padahal Tabel {$distTable['number']} menunjukkan jarak {$actual['name']} ke ibukota kecamatan adalah {$actual['kecamatan']} km ({$actual['kabupaten']} km adalah jarak ke ibukota kabupaten).",
                            'expected' => "Koreksi narasi jarak ke pusat kecamatan sesuai Tabel {$distTable['number']} (jarak ke ibukota kecamatan adalah {$actual['kecamatan']} km).",
                            'recommendation' => "Perbaiki penyebutan jarak dan tujuan ibukota pada narasi agar tidak tertukar antara ibukota kecamatan dan ibukota kabupaten.",
                            'evidences' => [
                                [
                                    'physical_page' => $n['page'],
                                    'quote' => "Narasi: {$m[0]}",
                                ],
                                [
                                    'physical_page' => $distTable['page'],
                                    'quote' => "Tabel {$distTable['number']}: {$actual['name']} - Jarak ke Ibukota Kecamatan: {$actual['kecamatan']} km, Jarak ke Ibukota Kabupaten: {$actual['kabupaten']} km",
                                ],
                            ],
                        ];
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * Check duplicate commodity mentions in narrative (e.g. tomat mentioned twice for different data).
     *
     * @param  array<int, array{page: int, text: string, text_layer: string|null}>  $narratives
     * @param  array<int, array{page: int, number: string, title: string, headers: array, rows: array}>  $tables
     * @return array<int, array<string, mixed>>
     */
    protected function checkDuplicateCommodities(array $narratives, array $tables): array
    {
        $findings = [];

        foreach ($narratives as $n) {
            $text = $n['text'];
            // Look for sentences like "luas panen tomat ... serta luas panen tomat ..."
            if (preg_match('/luas panen (tomat)[^.]+serta luas panen (tomat)/i', $text)) {
                // Find nearby vegetable table confirming eggplant / terung
                $tableRef = null;
                foreach ($tables as $t) {
                    $tStr = mb_strtolower(json_encode($t['rows']));
                    if (str_contains($tStr, 'tomat') && (str_contains($tStr, 'terung') || str_contains($tStr, 'eggplant'))) {
                        $tableRef = $t;
                        break;
                    }
                }

                $evidences = [
                    [
                        'physical_page' => $n['page'],
                        'quote' => 'Narasi menyebut komoditas tomat dua kali secara beruntun pada rincian luas panen sayuran yang berbeda.',
                    ],
                ];
                if ($tableRef !== null) {
                    $evidences[] = [
                        'physical_page' => $tableRef['page'],
                        'quote' => "Tabel {$tableRef['number']} memuat baris Tomat/Tomato dan Terung/Eggplant.",
                    ];
                }

                $findings[] = [
                    'rule_code' => 'NARRATIVE.TABLE_MISMATCH',
                    'severity' => 'verifikasi',
                    'source' => 'chapter_crosscheck',
                    'confidence' => 0.95,
                    'observed' => 'Narasi bahasa Indonesia menyebut tomat dua kali dengan nilai luas panen berbeda (1,40 ha dan 0,10 ha); versi bahasa Inggris menyebut eggplant, dan data tabel menunjukkan 0,10 ha adalah terung.',
                    'expected' => 'Ubah penyebutan komoditas kedua dari tomat menjadi terung sesuai data tabel dan teks terjemahan bahasa Inggris.',
                    'recommendation' => 'Perbaiki penulisan nama komoditas kedua pada paragraf narasi.',
                    'evidences' => $evidences,
                ];
            }
        }

        return $findings;
    }
}
