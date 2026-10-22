<?php

declare(strict_types=1);

namespace App\Audit;

class ChapterMapBuilder
{
    /**
     * Build an authoritative chapter map for a publication from its page results.
     *
     * @param  array<int, array<string, mixed>>  $pages
     * @return array<string, mixed>
     */
    public function build(array $pages): array
    {
        $totalPages = count($pages);
        $chapters = [];
        $source = 'fallback';
        $warnings = [];

        // 1. Scan for chapter divider pages (page_type === 'chapter')
        $dividerChapters = [];
        foreach ($pages as $p) {
            $pageType = $p['page_type'] ?? '';
            $phys = (int) ($p['physical_page'] ?? 0);
            $res = $p['result'] ?? [];
            $chapterTitle = $res['inventory']['chapter_title'] ?? ($res['inventory']['header'] ?? null);

            if ($pageType === 'chapter') {
                $rawTitle = (string) ($chapterTitle ?: 'Bab');
                $cleanTitle = $this->cleanChapterTitle($rawTitle);
                $dividerChapters[] = [
                    'physical_start' => $phys,
                    'raw_title' => $rawTitle,
                    'clean_title' => $cleanTitle,
                ];
            }
        }

        // 2. Scan for Table of Contents (TOC) entries if no divider pages
        $tocChapters = [];
        $offset = null;
        $printedToPhysicalOffsets = [];

        foreach ($pages as $p) {
            $phys = (int) ($p['physical_page'] ?? 0);
            $printed = $p['printed_page'] ?? null;

            if (is_numeric($printed) && (int) $printed > 0) {
                $printedToPhysicalOffsets[] = $phys - (int) $printed;
            }

            $res = $p['result'] ?? [];
            foreach ($res['contents'] ?? [] as $entry) {
                $type = $entry['type'] ?? '';
                $title = (string) ($entry['title'] ?? '');
                $targetPage = $entry['target_page'] ?? null;

                if ($type === 'chapter' || preg_match('/^(bab\s+\d+|chapter\s+\d+|geografi|pemerintahan|penduduk|sosial|pertanian|pariwisata|perbankan)/i', $title)) {
                    $cleanTitle = $this->cleanChapterTitle($title);
                    $tocChapters[] = [
                        'title' => $cleanTitle,
                        'printed_start' => is_numeric($targetPage) ? (int) $targetPage : null,
                    ];
                }
            }
        }

        // Calculate median offset if available
        if (! empty($printedToPhysicalOffsets)) {
            sort($printedToPhysicalOffsets);
            $mid = (int) floor(count($printedToPhysicalOffsets) / 2);
            $offset = $printedToPhysicalOffsets[$mid];
        }

        // 3. Reconcile signals: prefer dividers if >= 2 found, else TOC with offset
        if (count($dividerChapters) >= 2) {
            $source = 'chapter_dividers';
            foreach ($dividerChapters as $idx => $d) {
                $num = $idx + 1;
                $label = "Bab {$num} {$d['clean_title']}";
                $chapters[] = [
                    'number' => $num,
                    'title' => $d['clean_title'],
                    'label' => $label,
                    'physical_start' => $d['physical_start'],
                    'physical_end' => null,
                ];
            }
        } elseif (! empty($tocChapters) && $offset !== null) {
            $source = 'contents';
            foreach ($tocChapters as $idx => $tc) {
                $num = $idx + 1;
                $physStart = $tc['printed_start'] !== null ? $tc['printed_start'] + $offset : null;
                $label = "Bab {$num} {$tc['title']}";
                $chapters[] = [
                    'number' => $num,
                    'title' => $tc['title'],
                    'label' => $label,
                    'physical_start' => $physStart,
                    'physical_end' => null,
                ];
            }
        }

        // 4. Fill physical_end for each chapter
        $maxPhysPage = 0;
        foreach ($pages as $p) {
            $phys = (int) ($p['physical_page'] ?? 0);
            if ($phys > $maxPhysPage) {
                $maxPhysPage = $phys;
            }
        }

        $frontMatterEnd = 1;
        $backMatterStart = $maxPhysPage > 0 ? $maxPhysPage + 1 : $totalPages + 1;

        foreach ($pages as $p) {
            $pt = $p['page_type'] ?? '';
            $phys = (int) ($p['physical_page'] ?? 0);
            if (in_array($pt, ['front_cover', 'title', 'catalogue', 'team', 'preface', 'contents', 'table_list', 'figure_list'], true)) {
                $frontMatterEnd = max($frontMatterEnd, $phys);
            }
            if (in_array($pt, ['bibliography', 'back_cover'], true)) {
                $backMatterStart = min($backMatterStart, $phys);
            }
        }

        $chapterCount = count($chapters);
        for ($i = 0; $i < $chapterCount; $i++) {
            if ($i < $chapterCount - 1) {
                $nextStart = $chapters[$i + 1]['physical_start'];
                $chapters[$i]['physical_end'] = $nextStart !== null ? $nextStart - 1 : null;
            } else {
                $chapters[$i]['physical_end'] = max($chapters[$i]['physical_start'] ?? 1, $backMatterStart - 1);
            }
        }

        return [
            'source' => $source,
            'offset' => $offset,
            'front_matter_end' => $frontMatterEnd,
            'back_matter_start' => $backMatterStart,
            'chapters' => $chapters,
            'warnings' => $warnings,
        ];
    }

    /**
     * Resolve chapter for a given physical page using the chapter map.
     *
     * @param  array<string, mixed>  $chapterMap
     * @return array<string, mixed>|null
     */
    public function resolveChapterForPage(array $chapterMap, int $physicalPage): ?array
    {
        foreach ($chapterMap['chapters'] ?? [] as $ch) {
            $start = $ch['physical_start'] ?? null;
            $end = $ch['physical_end'] ?? null;

            if ($start !== null && $end !== null) {
                if ($physicalPage >= $start && $physicalPage <= $end) {
                    return $ch;
                }
            } elseif ($start !== null && $physicalPage >= $start) {
                return $ch;
            }
        }

        return null;
    }

    /**
     * Clean and normalize raw chapter title into proper standard casing.
     */
    private function cleanChapterTitle(string $raw): string
    {
        // Strip out BAB 1, CHAPTER 1, etc.
        $clean = preg_replace('/^(bab|chapter)\s*([0-9ivx]+)\s*[:.\-]?\s*/i', '', trim($raw));
        // Strip out bilingual translation slash if present (e.g. GEOGRAFI / Geography -> Geografi)
        $parts = explode('/', (string) $clean);
        $primary = trim($parts[0]);

        // Fix common spelling or missing commas
        if (stripos($primary, 'PARAWISATA') !== false || stripos($primary, 'PARIWISATA') !== false) {
            return 'Pariwisata, Transportasi, dan Komunikasi';
        }
        if (stripos($primary, 'SOSIAL') !== false) {
            return 'Sosial dan Kesejahteraan Rakyat';
        }
        if (stripos($primary, 'PERBANKAN') !== false) {
            return 'Perbankan, Koperasi, dan Perdagangan';
        }

        $title = mb_convert_case($primary, MB_CASE_TITLE, 'UTF-8');
        // Lowercase conjunctions
        return preg_replace_callback('/\b(Dan|Di|Ke|Dari|Pada|Untuk)\b/u', fn ($m) => mb_strtolower($m[0]), $title);
    }
}
