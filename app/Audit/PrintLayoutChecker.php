<?php

declare(strict_types=1);

namespace App\Audit;

class PrintLayoutChecker
{
    /**
     * @param  array{pages: array<int, array<string, mixed>>, anchors: array<string, mixed>, uncertainties: array<int, string>}  $anatomy
     * @return array<string, mixed>
     */
    public function check(array $anatomy): array
    {
        $findings = [];
        $limitations = [];
        $anchors = $anatomy['anchors'];
        $pages = $anatomy['pages'];

        $lastRomanPrinted = $anchors['last_roman_printed'];
        $lastArabicPrinted = $anchors['last_arabic_printed'];

        if (! empty($anatomy['uncertainties'])) {
            $limitations = array_merge($limitations, $anatomy['uncertainties']);
        }

        if ($lastArabicPrinted === null) {
            $limitations[] = 'Anchor penomoran Arab tidak lengkap atau belum terbaca.';

            return [
                'decision' => 'needs_verification',
                'actual' => [],
                'expected' => [],
                'findings' => [],
                'limitations' => $limitations,
            ];
        }

        // 1. Roman end check
        $expectedRoman = $lastRomanPrinted ?? 'ii';
        if ($lastRomanPrinted !== null) {
            $romanVal = $this->romanToInt($lastRomanPrinted);
            $lastRomanPhysical = $anchors['last_roman_physical'];
            $firstArabicPhysical = null;
            foreach ($pages as $p) {
                if ($p['physical_page'] > $lastRomanPhysical && $p['numbering_system'] === 'arabic') {
                    $firstArabicPhysical = $p['physical_page'];
                    break;
                }
            }
            $firstArabicPhysical ??= ($lastRomanPhysical + 1);

            if ($romanVal % 2 !== 0) {
                // Odd roman end requires a trailing blank page to complete to even
                $hasRomanBlank = false;
                foreach ($pages as $p) {
                    if ($p['physical_page'] > $lastRomanPhysical && $p['physical_page'] < $firstArabicPhysical) {
                        if ($p['page_type'] === 'blank' || $p['has_content'] === false) {
                            $hasRomanBlank = true;
                            break;
                        }
                    }
                }

                if ($hasRomanBlank) {
                    $expectedRoman = $this->intToRoman($romanVal + 1);
                } else {
                    $expectedEvenRoman = $this->intToRoman($romanVal + 1);
                    $findings[] = [
                        'rule_code' => 'PRINT.ROMAN_END',
                        'section' => 'Susunan halaman pendahuluan romawi',
                        'severity' => 'wajib',
                        'source' => 'deterministic',
                        'confidence' => 0.99,
                        'observed' => "Bagian pendahuluan romawi berakhir pada halaman ganjil (hlm. {$lastRomanPrinted}) tanpa halaman kosong penutup.",
                        'expected' => "Bagian pendahuluan romawi berakhir genap ({$expectedEvenRoman}) dengan halaman kosong penutup tanpa nomor.",
                        'recommendation' => "Tambahkan satu halaman kosong penutup pendahuluan sebelum bab pertama sehingga dihitung sampai {$expectedEvenRoman}.",
                        'evidences' => [
                            ['physical_page' => $lastRomanPhysical, 'quote' => "Halaman tercetak {$lastRomanPrinted}"],
                        ],
                    ];
                }
            }
        }

        // 2. Trailing white pages check
        // Odd arabic last content requires 2 pure white pages; Even requires 1 pure white page
        $isOddArabic = ((int) $lastArabicPrinted) % 2 !== 0;
        $expectedTrailingBlanks = $isOddArabic ? 2 : 1;
        $actualTrailingBlanks = count($anchors['trailing_blank_physicals']);

        if ($actualTrailingBlanks !== $expectedTrailingBlanks) {
            $diff = abs($actualTrailingBlanks - $expectedTrailingBlanks);
            $action = $actualTrailingBlanks < $expectedTrailingBlanks ? 'Kurang' : 'Kelebihan';
            $findings[] = [
                'rule_code' => 'PRINT.TRAILING_BLANKS',
                'section' => 'Susunan halaman putih akhir',
                'severity' => 'wajib',
                'source' => 'deterministic',
                'confidence' => 0.99,
                'observed' => "Ditemukan {$actualTrailingBlanks} halaman putih penutup sebelum kover belakang.",
                'expected' => "Konten terakhir halaman tercetak {$lastArabicPrinted} (".($isOddArabic ? 'ganjil' : 'genap').") memerlukan tepat {$expectedTrailingBlanks} halaman putih penutup.",
                'recommendation' => "{$action} {$diff} halaman putih penutup sebelum kover belakang.",
                'evidences' => [
                    ['physical_page' => $anchors['last_arabic_physical'], 'quote' => "Konten terakhir hlm. {$lastArabicPrinted}"],
                ],
            ];
        }

        // 3. Catalogue page count format
        $expectedCatalogueCount = "{$expectedRoman}+{$lastArabicPrinted} halaman/pages";

        return [
            'decision' => empty($findings) ? 'conform' : 'nonconform',
            'actual' => [
                'last_roman' => $lastRomanPrinted,
                'last_arabic' => (int) $lastArabicPrinted,
                'trailing_white_pages' => $actualTrailingBlanks,
            ],
            'expected' => [
                'catalogue_page_count' => $expectedCatalogueCount,
                'trailing_white_pages' => $expectedTrailingBlanks,
            ],
            'findings' => $findings,
            'limitations' => $limitations,
        ];
    }

    private function romanToInt(string $roman): int
    {
        $romans = [
            'm' => 1000, 'cm' => 900, 'd' => 500, 'cd' => 400,
            'c' => 100, 'xc' => 90, 'l' => 50, 'xl' => 40,
            'x' => 10, 'ix' => 9, 'v' => 5, 'iv' => 4, 'i' => 1,
        ];
        $roman = strtolower(trim($roman));
        $result = 0;
        foreach ($romans as $key => $value) {
            while (str_starts_with($roman, $key)) {
                $result += $value;
                $roman = substr($roman, strlen($key));
            }
        }

        return $result;
    }

    private function intToRoman(int $number): string
    {
        $map = [
            'm' => 1000, 'cm' => 900, 'd' => 500, 'cd' => 400,
            'c' => 100, 'xc' => 90, 'l' => 50, 'xl' => 40,
            'x' => 10, 'ix' => 9, 'v' => 5, 'iv' => 4, 'i' => 1,
        ];
        $result = '';
        foreach ($map as $roman => $value) {
            while ($number >= $value) {
                $result .= $roman;
                $number -= $value;
            }
        }

        return $result;
    }
}
