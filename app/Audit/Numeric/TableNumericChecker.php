<?php

declare(strict_types=1);

namespace App\Audit\Numeric;

class TableNumericChecker
{
    public function __construct(protected NumberParser $parser = new NumberParser) {}

    /**
     * Safely stringify a cell or nested header array into a flat string.
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
     * Run deterministic numeric calculations across all pages of a publication.
     *
     * @param  array<int, array<string, mixed>>  $pages
     * @return array<int, array<string, mixed>>
     */
    public function run(array $pages): array
    {
        $findings = [];

        // Pre-index multi-part / continuation tables (e.g. Table 3.1 across p.54 and p.55)
        $tablesByNumber = [];

        foreach ($pages as $p) {
            $phys = (int) ($p['physical_page'] ?? 0);
            $res = $p['result'] ?? [];
            $inv = $res['inventory'] ?? [];
            $tables = $inv['tables'] ?? ($res['tables'] ?? []);

            foreach ($tables as $t) {
                $tNum = trim((string) ($t['number'] ?? ''));
                if ($tNum !== '') {
                    $tablesByNumber[$tNum][] = [
                        'page' => $phys,
                        'table' => $t,
                        'text_layer' => $p['text_layer'] ?? null,
                    ];
                }

                // Check table self-contained row totals & column totals
                $tableFindings = $this->checkTable($t, $phys);
                foreach ($tableFindings as $tf) {
                    $findings[] = $tf;
                }
            }
        }

        // Cross-table & continuation checks (e.g. Table 3.1 continuation for sex ratio & density)
        $crossFindings = $this->checkContinuationTables($tablesByNumber);
        foreach ($crossFindings as $cf) {
            $findings[] = $cf;
        }

        return $findings;
    }

    /**
     * Check row totals and column totals within a single table.
     *
     * @param  array<string, mixed>  $table
     * @return array<int, array<string, mixed>>
     */
    public function checkTable(array $table, int $page): array
    {
        $findings = [];
        $tNum = trim((string) ($table['number'] ?? ''));
        $title = trim((string) ($table['title'] ?? ''));
        $headers = $table['headers'] ?? [];
        $rows = $table['rows'] ?? [];

        if (count($rows) < 2 || empty($headers)) {
            return [];
        }

        // 1. Column totals: e.g. Negeri + Swasta = Jumlah/Total
        $colTotalFindings = $this->checkColumnTotals($table, $page);
        foreach ($colTotalFindings as $ctf) {
            $findings[] = $ctf;
        }

        // 2. Row totals: look for total row at the end
        $lastRow = end($rows);
        $firstCell = mb_strtolower(trim($this->stringify($lastRow[0] ?? '')));

        // Check if last row is an aggregate / total row
        $isTotalRow = preg_match('/\b(jumlah|total|kecamatan|kabupaten|kota|provinsi)\b/i', $firstCell) === 1
            || (mb_strlen($firstCell) >= 3 && stripos($title, $firstCell) !== false)
            || isset($table['total']);

        if ($isTotalRow) {
            $componentRows = array_slice($rows, 0, -1);
            $numCols = count($headers);

            for ($colIdx = 1; $colIdx < $numCols; $colIdx++) {
                $colHeader = trim($this->stringify($headers[$colIdx] ?? ''));

                // Skip non-additive columns (codes, IDs, percentages, ratios, averages)
                if (preg_match('/(kode|code|npsn|\bid\b|nomor|number|tahun|year|persen|%|rasio|ratio|kepadatan|density|rata-rata|average)/i', $colHeader)) {
                    continue;
                }

                $printedTotalRaw = $lastRow[$colIdx] ?? null;
                $printedTotal = $this->parser->parse($printedTotalRaw);

                if ($printedTotal === null) {
                    continue;
                }

                $calcTotal = 0.0;
                $hasValidComponents = false;
                $allIntegers = true;

                foreach ($componentRows as $compRow) {
                    $valRaw = $compRow[$colIdx] ?? null;
                    $val = $this->parser->parse($valRaw);

                    if ($val !== null) {
                        $calcTotal += $val;
                        $hasValidComponents = true;
                        if (floor($val) !== $val) {
                            $allIntegers = false;
                        }
                    }
                }

                if (! $hasValidComponents) {
                    continue;
                }

                $tolerance = $allIntegers ? 0.001 : 0.05;
                $diff = abs($calcTotal - $printedTotal);

                if ($diff > $tolerance) {
                    $calcFormatted = $allIntegers ? (string) ((int) round($calcTotal)) : number_format($calcTotal, 2, ',', '.');
                    $printedFormatted = (string) $printedTotalRaw;

                    $findings[] = [
                        'rule_code' => 'TABLE.TOTAL',
                        'severity' => 'wajib',
                        'source' => 'numeric_check',
                        'confidence' => 0.95,
                        'observed' => "Pada Tabel {$tNum} ({$title}) baris total kolom \"{$colHeader}\", nilai tercetak adalah {$printedFormatted}, namun hasil penjumlahan ".(count($componentRows))." baris rincian adalah {$calcFormatted}.",
                        'expected' => "Nilai total seharusnya {$calcFormatted} sesuai hasil penjumlahan baris rincian.",
                        'recommendation' => "Perbaiki angka total kolom \"{$colHeader}\" agar konsisten dengan penjumlahan baris di atasnya.",
                        'evidences' => [
                            [
                                'physical_page' => $page,
                                'quote' => "Tabel {$tNum} kolom {$colHeader}: tercetak {$printedFormatted}, hitung ulang = {$calcFormatted}",
                            ],
                        ],
                    ];
                }
            }
        }

        return $findings;
    }

    /**
     * Check if a table has component columns that must add up to a Total column in the same row.
     * E.g. Negeri (col 2) + Swasta (col 4) = Total (col 6).
     *
     * @param  array<string, mixed>  $table
     * @return array<int, array<string, mixed>>
     */
    protected function checkColumnTotals(array $table, int $page): array
    {
        $findings = [];
        $tNum = trim((string) ($table['number'] ?? ''));
        $title = trim((string) ($table['title'] ?? ''));
        $headers = $table['headers'] ?? [];
        $rows = $table['rows'] ?? [];

        // Find years or suffixes in headers, e.g. 2024/2025, 2025/2026, 2025
        $periods = [];
        foreach ($headers as $idx => $h) {
            $hStr = $this->stringify($h);
            if (preg_match('/(\b20\d\d(?:\/20\d\d)?\b)/', $hStr, $m)) {
                $periods[$m[1]][] = $idx;
            }
        }

        foreach ($periods as $period => $colIndices) {
            $negeriCol = null;
            $swastaCol = null;
            $totalCol = null;

            foreach ($colIndices as $ci) {
                $h = mb_strtolower($this->stringify($headers[$ci] ?? ''));
                if (str_contains($h, 'negeri') || str_contains($h, 'public')) {
                    $negeriCol = $ci;
                } elseif (str_contains($h, 'swasta') || str_contains($h, 'private')) {
                    $swastaCol = $ci;
                } elseif (str_contains($h, 'jumlah') || str_contains($h, 'total')) {
                    $totalCol = $ci;
                }
            }

            if ($negeriCol !== null && $swastaCol !== null && $totalCol !== null) {
                foreach ($rows as $row) {
                    $rowLabel = trim($this->stringify($row[0] ?? ''));
                    $negeriVal = $this->parser->parse($row[$negeriCol] ?? null);
                    $swastaVal = $this->parser->parse($row[$swastaCol] ?? null);
                    $totalVal = $this->parser->parse($row[$totalCol] ?? null);

                    if ($negeriVal !== null && $swastaVal !== null && $totalVal !== null) {
                        $expectedSum = $negeriVal + $swastaVal;
                        if (abs($expectedSum - $totalVal) > 0.001) {
                            $findings[] = [
                                'rule_code' => 'TABLE.TOTAL',
                                'severity' => 'wajib',
                                'source' => 'numeric_check',
                                'confidence' => 0.95,
                                'observed' => "Pada Tabel {$tNum} ({$title}) periode {$period} baris \"{$rowLabel}\", total tercetak adalah {$totalVal}, namun penjumlahan Negeri ({$negeriVal}) + Swasta ({$swastaVal}) adalah {$expectedSum}.",
                                'expected' => "Nilai total kolom seharusnya {$expectedSum}.",
                                'recommendation' => "Perbaiki total penjumlahan kolom Negeri dan Swasta pada baris {$rowLabel}.",
                                'evidences' => [
                                    [
                                        'physical_page' => $page,
                                        'quote' => "Tabel {$tNum} {$rowLabel} ({$period}): Negeri {$negeriVal} + Swasta {$swastaVal} = {$expectedSum}, tercetak {$totalVal}",
                                    ],
                                ],
                            ];
                        }
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * Check continuation tables: e.g. Table 3.1 p.54 (L, P) and p.55 (Sex Ratio = L/P*100).
     *
     * @param  array<string, array<int, array{page: int, table: array<string, mixed>, text_layer: string|null}>>  $tablesByNumber
     * @return array<int, array<string, mixed>>
     */
    protected function checkContinuationTables(array $tablesByNumber): array
    {
        $findings = [];

        foreach ($tablesByNumber as $tNum => $instances) {
            if (count($instances) < 2) {
                continue;
            }

            // Look for Table 3.1 or population table
            $popTable = null;
            $ratioTable = null;

            foreach ($instances as $inst) {
                $hdrs = mb_strtolower($this->stringify($inst['table']['headers'] ?? []));
                if (str_contains($hdrs, 'laki-laki') || str_contains($hdrs, 'male')) {
                    $popTable = $inst;
                }
                if (str_contains($hdrs, 'rasio jenis kelamin') || str_contains($hdrs, 'sex ratio')) {
                    $ratioTable = $inst;
                }
            }

            if ($popTable !== null && $ratioTable !== null) {
                // Map population data by village name
                $popRows = $popTable['table']['rows'] ?? [];
                $ratioRows = $ratioTable['table']['rows'] ?? [];

                $villagePop = [];
                foreach ($popRows as $r) {
                    $vName = mb_strtolower(trim($this->stringify($r[0] ?? '')));
                    $l = $this->parser->parse($r[1] ?? null);
                    $p = $this->parser->parse($r[2] ?? null);
                    if ($vName !== '' && $l !== null && $p !== null && $p > 0) {
                        $villagePop[$vName] = ['l' => $l, 'p' => $p, 'raw_l' => $r[1] ?? '', 'raw_p' => $r[2] ?? ''];
                    }
                }

                // Check sex ratio in continuation table
                $ratioHeaders = $ratioTable['table']['headers'] ?? [];
                $ratioCol = null;
                foreach ($ratioHeaders as $idx => $rh) {
                    $rhStr = $this->stringify($rh);
                    if (preg_match('/(rasio|ratio)/i', $rhStr)) {
                        $ratioCol = $idx;
                        break;
                    }
                }

                if ($ratioCol !== null) {
                    foreach ($ratioRows as $rr) {
                        $vName = mb_strtolower(trim($this->stringify($rr[0] ?? '')));
                        $printedRatioRaw = $rr[$ratioCol] ?? null;
                        $printedRatio = $this->parser->parse($printedRatioRaw);

                        if (isset($villagePop[$vName]) && $printedRatio !== null) {
                            $l = $villagePop[$vName]['l'];
                            $p = $villagePop[$vName]['p'];
                            $calcRatio = round(($l / $p) * 100, 2);

                            if (abs($calcRatio - $printedRatio) > 0.05) {
                                $vDisplayName = trim($this->stringify($rr[0] ?? ''));
                                $findings[] = [
                                    'rule_code' => 'TABLE.CONSISTENCY',
                                    'severity' => 'wajib',
                                    'source' => 'numeric_check',
                                    'confidence' => 0.95,
                                    'observed' => "Pada Tabel {$tNum} baris \"{$vDisplayName}\", rasio jenis kelamin tertulis {$printedRatioRaw}, namun perhitungan ulang dari penduduk laki-laki ({$villagePop[$vName]['raw_l']}) dan perempuan ({$villagePop[$vName]['raw_p']}) menghasilkan ".number_format($calcRatio, 2, ',', '.').'.',
                                    'expected' => 'Rasio jenis kelamin seharusnya '.number_format($calcRatio, 2, ',', '.')." (rumus: L/P \u{00D7} 100).",
                                    'recommendation' => "Perbaiki rasio jenis kelamin pada baris {$vDisplayName}.",
                                    'evidences' => [
                                        [
                                            'physical_page' => $ratioTable['page'],
                                            'quote' => "{$vDisplayName}: tercetak {$printedRatioRaw}; hitung ulang {$villagePop[$vName]['raw_l']}/{$villagePop[$vName]['raw_p']}\u{00D7}100 = ".number_format($calcRatio, 2, ',', '.'),
                                        ],
                                        [
                                            'physical_page' => $popTable['page'],
                                            'quote' => "{$vDisplayName}: Laki-laki {$villagePop[$vName]['raw_l']}, Perempuan {$villagePop[$vName]['raw_p']}",
                                        ],
                                    ],
                                ];
                            }
                        }
                    }
                }
            }
        }

        return $findings;
    }
}
