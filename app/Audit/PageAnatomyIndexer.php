<?php

declare(strict_types=1);

namespace App\Audit;

class PageAnatomyIndexer
{
    /**
     * @param  array<int, array<string, mixed>>  $pageResults
     * @return array{pages: array<int, array<string, mixed>>, anchors: array<string, mixed>, uncertainties: array<int, string>}
     */
    public function build(array $pageResults): array
    {
        $pages = [];
        $uncertainties = [];
        $anchors = [
            'first_roman_physical' => null,
            'last_roman_physical' => null,
            'last_roman_printed' => null,
            'first_arabic_physical' => null,
            'last_arabic_physical' => null,
            'last_arabic_printed' => null,
            'content_end_physical' => null,
            'trailing_blank_physicals' => [],
        ];

        $countedPosition = 0;

        foreach ($pageResults as $idx => $result) {
            $physicalPage = (int) ($result['physical_page'] ?? ($idx + 1));
            $printedRaw = isset($result['printed_page']) && trim((string) $result['printed_page']) !== ''
                ? trim((string) $result['printed_page'])
                : null;
            $pageType = (string) ($result['page_type'] ?? 'narrative');

            // Determine numbering system
            $numSystem = null;
            $hasVisibleNumber = $printedRaw !== null;
            if ($printedRaw !== null) {
                if (ctype_digit($printedRaw)) {
                    $numSystem = 'arabic';
                } elseif ($this->isRoman($printedRaw)) {
                    $numSystem = 'roman';
                    if ($this->isNonCanonicalRoman($printedRaw)) {
                        $uncertainties[] = "Halaman {$physicalPage} menggunakan notasi romawi tidak baku: '{$printedRaw}'.";
                    }
                } else {
                    $uncertainties[] = "Halaman {$physicalPage} memiliki nomor halaman tidak dikenal: '{$printedRaw}'.";
                }
            }

            // Determine content & blank kind
            $hasContent = null;
            $blankKind = null;

            if (isset($result['is_pure_white'])) {
                // V2 explicit flag
                $hasContent = ! (bool) $result['is_pure_white'];
                $blankKind = $result['is_pure_white'] ? 'pure_white' : ($result['has_ornament'] ?? false ? 'ornamented' : null);
            } elseif ($pageType === 'blank') {
                $hasContent = false;
                $blankKind = 'pure_white';
            } elseif (in_array($pageType, ['chapter', 'front_cover', 'title', 'back_cover'], true)) {
                $hasContent = true;
                $blankKind = null;
            } elseif (isset($result['text']) && trim($result['text']) !== '') {
                $hasContent = true;
                $blankKind = null;
            } else {
                // Legacy empty text without explicit blank type: unknown
                $hasContent = null;
                $blankKind = null;
            }

            // Running title presence
            $runningTitle = $result['inventory']['running_title_id'] ?? $result['inventory']['header'] ?? null;
            $hasRunningTitle = $runningTitle !== null && trim((string) $runningTitle) !== '';

            // Counted position logic: Covers are usually not counted in page totals
            $isCounted = ! in_array($pageType, ['front_cover', 'back_cover'], true);
            if ($isCounted) {
                $countedPosition++;
            }

            $pageEntry = [
                'physical_page' => $physicalPage,
                'printed_page' => $printedRaw,
                'numbering_system' => $numSystem,
                'counted_position' => $countedPosition,
                'is_counted' => $isCounted,
                'page_type' => $pageType,
                'has_content' => $hasContent,
                'has_running_title' => $hasRunningTitle,
                'has_visible_number' => $hasVisibleNumber,
                'blank_kind' => $blankKind,
            ];

            $pages[] = $pageEntry;

            // Track anchors
            if ($isCounted) {
                if ($numSystem === 'roman') {
                    if ($anchors['first_roman_physical'] === null) {
                        $anchors['first_roman_physical'] = $physicalPage;
                    }
                    $anchors['last_roman_physical'] = $physicalPage;
                    $anchors['last_roman_printed'] = strtolower((string) $printedRaw);
                } elseif ($numSystem === 'arabic') {
                    if ($anchors['first_arabic_physical'] === null) {
                        $anchors['first_arabic_physical'] = $physicalPage;
                    }
                    $anchors['last_arabic_physical'] = $physicalPage;
                    $anchors['last_arabic_printed'] = (int) $printedRaw;
                }
            }

            if ($isCounted && $hasContent === true && $pageType !== 'blank') {
                $anchors['content_end_physical'] = $physicalPage;
            }
        }

        // Identify trailing white pages after last arabic content before back cover
        $lastArabicPhysical = $anchors['last_arabic_physical'] ?? ($anchors['content_end_physical'] ?? 0);
        $totalPhysical = count($pages);
        for ($i = 0; $i < $totalPhysical; $i++) {
            $p = $pages[$i];
            if ($p['physical_page'] > $lastArabicPhysical && $p['page_type'] !== 'back_cover' && $p['blank_kind'] === 'pure_white') {
                $anchors['trailing_blank_physicals'][] = $p['physical_page'];
            }
        }

        return [
            'pages' => $pages,
            'anchors' => $anchors,
            'uncertainties' => $uncertainties,
        ];
    }

    private function isRoman(string $value): bool
    {
        return (bool) preg_match('/^(?=[mdclxvi])m*(c[md]|d?c*)(x[cl]|l?x*)(i[xv]|v?i*)$/i', trim($value));
    }

    private function isNonCanonicalRoman(string $value): bool
    {
        return (bool) preg_match('/(?:iiii|xxxx|cccc|mmmm)/i', trim($value));
    }
}
