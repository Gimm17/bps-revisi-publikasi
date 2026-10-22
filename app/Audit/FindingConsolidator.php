<?php

declare(strict_types=1);

namespace App\Audit;

use App\Models\Finding;
use Illuminate\Support\Collection;

class FindingConsolidator
{
    /**
     * Consolidate recurring findings for clean presentation in Excel export and UI.
     *
     * @param  Collection<int, Finding>|array<int, Finding>  $findings
     * @return Collection<int, Finding>
     */
    public function consolidate($findings): Collection
    {
        $collection = $findings instanceof Collection ? $findings : collect($findings);

        $runningTitleGroups = [];
        $symbolGroups = [];
        $others = [];

        foreach ($collection as $f) {
            $status = $f->review_status ?? 'unreviewed';
            $sev = $f->severity ?? 'saran';
            $rule = $f->rule_code ?? '';

            // Group 1: Missing running title
            if ($rule === 'RUNNING.TITLE' && ! str_contains(mb_strtolower($f->observed ?? ''), 'berbeda')) {
                $key = "{$rule}|{$sev}|{$status}|".($f->review_note ?? '');
                $runningTitleGroups[$key][] = $f;
            }
            // Group 2: TABLE.SYMBOL hyphen (-) vs en dash (–)
            elseif ($rule === 'TABLE.SYMBOL' && (str_contains(mb_strtolower($f->observed ?? ''), 'tanda hubung') || str_contains(mb_strtolower($f->observed ?? ''), 'strip') || str_contains(mb_strtolower($f->observed ?? ''), 'en dash'))) {
                $key = "{$rule}|{$sev}|{$status}|".($f->review_note ?? '');
                $symbolGroups[$key][] = $f;
            } else {
                $others[] = $f;
            }
        }

        // Process running title groups (consolidate if >= 3)
        foreach ($runningTitleGroups as $group) {
            if (count($group) < 3) {
                foreach ($group as $f) {
                    $others[] = $f;
                }

                continue;
            }

            $first = $group[0];
            $pages = $this->collectPages($group);
            $pageListStr = implode(', ', $pages);

            $consolidated = clone $first;
            $consolidated->consolidated_ids = array_map(fn ($item) => $item->id, $group);
            $consolidated->observed = 'Running title tidak tercantum pada '.count($pages).' halaman ganjil bernomor Arab.';
            $consolidated->expected = 'Sesuai pedoman tata letak BPS, seluruh halaman isi bernomor Arab ganjil wajib memuat running title judul bab.';
            $consolidated->recommendation = 'Lengkapi running title pada seluruh halaman ganjil terkait (PDF hlm. '.$pageListStr.').';
            $consolidated->evidences = array_map(fn ($p) => ['physical_page' => $p, 'quote' => 'Running title tidak tercantum', 'bbox' => null], $pages);

            $others[] = $consolidated;
        }

        // Process table symbol hyphen vs en dash groups (consolidate if >= 3)
        foreach ($symbolGroups as $group) {
            if (count($group) < 3) {
                foreach ($group as $f) {
                    $others[] = $f;
                }

                continue;
            }

            $first = $group[0];
            $pages = $this->collectPages($group);
            $tableRefs = [];
            foreach ($group as $item) {
                if (preg_match('/Tabel\s+([\d.]+)/i', $item->observed ?? '', $m)) {
                    $tableRefs[] = "Tabel {$m[1]}";
                }
            }
            $tableRefs = array_values(array_unique($tableRefs));
            $tableListStr = ! empty($tableRefs) ? implode(', ', array_slice($tableRefs, 0, 8)).(count($tableRefs) > 8 ? ' dst.' : '') : count($group).' tabel';

            $consolidated = clone $first;
            $consolidated->consolidated_ids = array_map(fn ($item) => $item->id, $group);
            $consolidated->observed = 'Tanda hubung (-) digunakan untuk menyatakan nilai nihil pada '.count($group)." butir ({$tableListStr}).";
            $consolidated->expected = 'Sesuai pedoman BPS, lambang nilai nihil/nol wajib menggunakan tanda pisah en dash (–), bukan tanda hubung (-).';
            $consolidated->recommendation = 'Ganti simbol tanda hubung (-) menjadi en dash (–) pada seluruh tabel terkait (PDF hlm. '.implode(', ', $pages).').';
            $consolidated->evidences = array_map(fn ($p) => ['physical_page' => $p, 'quote' => 'Simbol nihil (-) perlu diganti en dash (–)', 'bbox' => null], $pages);

            $others[] = $consolidated;
        }

        return collect($others);
    }

    /**
     * @param  array<int, Finding>  $group
     * @return array<int, int>
     */
    private function collectPages(array $group): array
    {
        $pages = [];
        foreach ($group as $item) {
            foreach ($item->evidences ?? [] as $ev) {
                $p = $ev['physical_page'] ?? $ev['page'] ?? null;
                if ($p !== null && is_numeric($p)) {
                    $pages[] = (int) $p;
                }
            }
        }
        $pages = array_values(array_unique($pages));
        sort($pages);

        return $pages;
    }
}
