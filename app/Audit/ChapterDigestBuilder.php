<?php

declare(strict_types=1);

namespace App\Audit;

class ChapterDigestBuilder
{
    /**
     * Build a structured digest string for an entire chapter from its pages.
     *
     * @param  array<string, mixed>  $chapterMeta
     * @param  array<int, array<string, mixed>>  $chapterPages
     * @return array{digest: string, table_count: int, narrative_count: int}
     */
    public function build(array $chapterMeta, array $pages): array
    {
        $chNum = $chapterMeta['number'] ?? 1;
        $chTitle = $chapterMeta['label'] ?? "Bab {$chNum}";
        $lines = ["=== {$chTitle} (Halaman Fisik {$chapterMeta['physical_start']}-{$chapterMeta['physical_end']}) ==="];

        $narrativesCount = 0;
        $tablesCount = 0;

        foreach ($pages as $p) {
            $phys = (int) ($p['physical_page'] ?? 0);
            $pType = $p['page_type'] ?? '';
            $res = $p['result'] ?? [];
            $text = trim((string) ($res['text'] ?? ''));

            if ($pType === 'narrative' && $text !== '') {
                $narrativesCount++;
                $lines[] = "\n--- Narasi Halaman {$phys} ---";
                $snippet = mb_substr($text, 0, 3000);
                $lines[] = $snippet;
            }

            $tables = $res['inventory']['tables'] ?? ($res['tables'] ?? []);
            foreach ($tables as $t) {
                $tablesCount++;
                $tNum = trim((string) ($t['number'] ?? ''));
                $tTitle = trim((string) ($t['title'] ?? ''));
                $headers = $this->formatCells((array) ($t['headers'] ?? []));

                $lines[] = "\n--- Tabel {$tNum} Halaman {$phys}: {$tTitle} ---";
                $lines[] = "Kolom: {$headers}";

                $rows = $t['rows'] ?? [];
                $sampleRows = array_slice($rows, 0, 15);
                foreach ($sampleRows as $r) {
                    $lines[] = '  '.$this->formatCells((array) $r);
                }
                if (count($rows) > 15) {
                    $lines[] = '  ... ('.(count($rows) - 15).' baris berikutnya)';
                    $lastRow = end($rows);
                    $lines[] = '  '.$this->formatCells((array) $lastRow);
                }
            }
        }

        $digest = implode("\n", $lines);

        return [
            'digest' => $digest,
            'table_count' => $tablesCount,
            'narrative_count' => $narrativesCount,
        ];
    }

    /**
     * Safely format cells handling potential nested arrays.
     */
    protected function formatCells(array $cells): string
    {
        $flat = array_map(function ($c) {
            if (is_array($c)) {
                return json_encode($c, JSON_UNESCAPED_UNICODE);
            }

            return (string) $c;
        }, $cells);

        return implode(' | ', $flat);
    }
    }
}
