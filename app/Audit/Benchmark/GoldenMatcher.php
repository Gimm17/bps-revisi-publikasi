<?php

declare(strict_types=1);

namespace App\Audit\Benchmark;

class GoldenMatcher
{
    /**
     * Run benchmark matching between golden items and candidate findings.
     *
     * @param  array<string, mixed>  $goldenDoc
     * @param  array<int, array<string, mixed>>  $findings
     * @return array<string, mixed>
     */
    public function evaluate(array $goldenDoc, array $findings): array
    {
        $items = $goldenDoc['items'] ?? [];
        $matchedMustFind = [];
        $matchedMustNotFind = [];
        $unmatchedMustFind = [];
        $matchedFindingIndices = [];

        $totalMustFindWeight = 0.0;
        $achievedMustFindWeight = 0.0;

        foreach ($items as $item) {
            $kind = $item['kind'] ?? 'must_find';
            $weight = (float) ($item['weight'] ?? 1.0);

            if ($kind === 'must_find') {
                $totalMustFindWeight += $weight;
            }

            $matchedFinding = null;
            $matchedIndex = null;

            foreach ($findings as $idx => $f) {
                if ($this->isMatch($item, $f)) {
                    $matchedFinding = $f;
                    $matchedIndex = $idx;
                    $matchedFindingIndices[$idx] = true;
                    break;
                }
            }

            if ($matchedFinding !== null) {
                if ($kind === 'must_find') {
                    $achievedMustFindWeight += $weight;
                    $matchedMustFind[] = [
                        'golden_id' => $item['id'],
                        'chapter' => $item['chapter'],
                        'weight' => $weight,
                        'golden_text' => $item['text'] ?? '',
                        'matched_observed' => $matchedFinding['observed'] ?? '',
                        'matched_rule' => $matchedFinding['rule_code'] ?? '',
                    ];
                } elseif ($kind === 'must_not_find') {
                    $matchedMustNotFind[] = [
                        'golden_id' => $item['id'],
                        'chapter' => $item['chapter'],
                        'golden_text' => $item['text'] ?? '',
                        'violation_observed' => $matchedFinding['observed'] ?? '',
                    ];
                }
            } else {
                if ($kind === 'must_find') {
                    $unmatchedMustFind[] = [
                        'golden_id' => $item['id'],
                        'chapter' => $item['chapter'],
                        'weight' => $weight,
                        'golden_text' => $item['text'] ?? '',
                        'pages' => $item['pages'] ?? [],
                        'keywords' => $item['keywords_any'] ?? [],
                    ];
                }
            }
        }

        $recall = $totalMustFindWeight > 0 ? round(($achievedMustFindWeight / $totalMustFindWeight) * 100, 2) : 0.0;
        $unmappedCount = count($findings) - count($matchedFindingIndices);

        // Group by chapter
        $chapterBreakdown = [];
        foreach ($items as $item) {
            $ch = $item['chapter'] ?? 'Umum';
            $kind = $item['kind'] ?? 'must_find';
            if ($kind !== 'must_find') {
                continue;
            }
            if (! isset($chapterBreakdown[$ch])) {
                $chapterBreakdown[$ch] = ['total_items' => 0, 'matched_items' => 0];
            }
            $chapterBreakdown[$ch]['total_items']++;
        }
        foreach ($matchedMustFind as $m) {
            $ch = $m['chapter'] ?? 'Umum';
            if (isset($chapterBreakdown[$ch])) {
                $chapterBreakdown[$ch]['matched_items']++;
            }
        }
        foreach ($chapterBreakdown as $ch => &$stat) {
            $stat['recall_pct'] = $stat['total_items'] > 0
                ? round(($stat['matched_items'] / $stat['total_items']) * 100, 1)
                : 0.0;
        }

        return [
            'publication' => $goldenDoc['publication'] ?? 'Publikasi',
            'total_findings' => count($findings),
            'total_golden_must_find' => count(array_filter($items, fn ($i) => ($i['kind'] ?? '') === 'must_find')),
            'matched_must_find_count' => count($matchedMustFind),
            'unmatched_must_find_count' => count($unmatchedMustFind),
            'must_not_find_violations' => count($matchedMustNotFind),
            'unmapped_findings_count' => $unmappedCount,
            'recall_percentage' => $recall,
            'chapter_breakdown' => $chapterBreakdown,
            'matched_items' => $matchedMustFind,
            'unmatched_items' => $unmatchedMustFind,
            'violations' => $matchedMustNotFind,
        ];
    }

    /**
     * Determine if a finding matches a golden item.
     */
    public function isMatch(array $goldenItem, array $finding): bool
    {
        $goldenPages = $goldenItem['pages'] ?? [];
        $findingPages = $this->extractFindingPages($finding);

        // 1. Page overlap check (toleransi +-1 jika ada halaman terdefinisi)
        if (! empty($goldenPages) && ! empty($findingPages)) {
            $hasOverlap = false;
            foreach ($findingPages as $fp) {
                foreach ($goldenPages as $gp) {
                    if (abs($fp - $gp) <= 1) {
                        $hasOverlap = true;
                        break 2;
                    }
                }
            }
            if (! $hasOverlap) {
                return false;
            }
        }

        // 2. Rule code or Keyword match
        $ruleCodes = $goldenItem['rule_codes'] ?? [];
        $fRule = $finding['rule_code'] ?? '';
        $ruleMatch = in_array($fRule, $ruleCodes, true);

        $corpus = mb_strtolower(
            ($finding['observed'] ?? '').' '.
            ($finding['expected'] ?? '').' '.
            ($finding['recommendation'] ?? '')
        );
        foreach ($finding['evidences'] ?? [] as $ev) {
            $corpus .= ' '.mb_strtolower($ev['quote'] ?? '');
        }

        $keywords = $goldenItem['keywords_any'] ?? [];
        $keywordMatch = false;
        foreach ($keywords as $kw) {
            $kwNorm = mb_strtolower(trim((string) $kw));
            if ($kwNorm !== '' && str_contains($corpus, $kwNorm)) {
                $keywordMatch = true;
                break;
            }
        }

        // For cancellations (must_not_find), finding must match the specific subject keywords
        $kind = $goldenItem['kind'] ?? 'must_find';
        if ($kind === 'must_not_find') {
            return $keywordMatch;
        }

        // Must match either rule code or at least one keyword (and if no keywords given, rule code must match)
        if (! empty($keywords)) {
            return $keywordMatch || ($ruleMatch && ! empty($goldenPages));
        }

        return $ruleMatch;
    }

    /**
     * @return array<int, int>
     */
    private function extractFindingPages(array $finding): array
    {
        $pages = [];
        foreach ($finding['evidences'] ?? [] as $ev) {
            $p = $ev['physical_page'] ?? $ev['page'] ?? null;
            if ($p !== null && is_numeric($p)) {
                $pages[] = (int) $p;
            }
        }

        return array_values(array_unique($pages));
    }
}
