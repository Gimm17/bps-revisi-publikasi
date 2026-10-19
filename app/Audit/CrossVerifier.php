<?php

declare(strict_types=1);

namespace App\Audit;

use App\Models\AiAgent;
use App\Models\Finding;
use App\Models\PageVerification;
use App\Models\Publication;
use App\Models\PublicationPage;

class CrossVerifier
{
    public function __construct(
        private readonly FindingWriter $writer
    ) {}

    /**
     * Check if a page qualifies for cross-verification based on publication mode.
     */
    public function shouldVerify(PublicationPage $page): bool
    {
        $mode = $page->publication->cross_verify_mode ?? 'high_risk';
        if ($mode === 'off') {
            return false;
        }

        if ($mode === 'all_pages') {
            return true;
        }

        // 'high_risk' mode: covers key structural pages AND statistical table pages
        $type = $page->page_type ?? '';
        $highRiskTypes = ['cover', 'title', 'catalogue', 'team', 'preface', 'toc', 'back_cover'];
        if (in_array($type, $highRiskTypes, true)) {
            return true;
        }

        // Also verify any page containing tables (with extra attention to numeric/alignment)
        $result = $page->result ?? [];
        $tables = $result['inventory']['tables'] ?? [];
        if (! empty($tables)) {
            return true;
        }

        // Check if primary findings detected table/number/format issues
        $hasTableFindings = $page->publication->findings()
            ->where('deleted', false)
            ->where(function ($q) use ($page) {
                $q->whereJsonContains('evidences', ['page' => $page->physical_page])
                    ->orWhere('section', 'table');
            })
            ->whereIn('rule_code', ['TABLE.FORMAT', 'TABLE.TOTAL', 'TABLE.NUMBER', 'TABLE.TITLE', 'TABLE.SYMBOL'])
            ->exists();

        return $hasTableFindings;
    }

    /**
     * Reconcile primary analysis with secondary verification.
     *
     * @param  array<string, mixed>  $verifyResult
     */
    public function reconcile(PublicationPage $page, AiAgent $verifierAgent, array $verifyResult): PageVerification
    {
        $primaryAgentId = $page->ai_agent_id;
        $verifierAgentId = $verifierAgent->id;

        $primaryFindings = $page->publication->findings()
            ->where('deleted', false)
            ->get()
            ->filter(function (Finding $f) use ($page) {
                foreach ($f->evidences ?? [] as $ev) {
                    if (($ev['page'] ?? $ev['physical_page'] ?? null) == $page->physical_page) {
                        return true;
                    }
                }

                return false;
            });

        $secondaryFindings = $verifyResult['findings'] ?? [];
        $confirmedCount = 0;
        $matchedSecondaryIndexes = [];

        // 1. Check which primary findings are confirmed by secondary
        foreach ($primaryFindings as $finding) {
            $isConfirmed = false;
            foreach ($secondaryFindings as $idx => $sec) {
                if (isset($sec['rule_code']) && $sec['rule_code'] === $finding->rule_code) {
                    $isConfirmed = true;
                    $matchedSecondaryIndexes[$idx] = true;
                    break;
                }
            }

            if ($isConfirmed) {
                $confirmedCount++;
                $agentIds = array_values(array_unique(array_filter([$primaryAgentId, $verifierAgentId])));
                $finding->update([
                    'agreement' => 'confirmed',
                    'agent_ids' => $agentIds,
                ]);
            } else {
                $agentIds = array_values(array_unique(array_filter([$primaryAgentId])));
                $finding->update([
                    'agreement' => 'single_agent',
                    'agent_ids' => $agentIds,
                ]);
            }
        }

        // 2. Add novel findings spotted by secondary agent that primary missed
        $novelCount = 0;
        foreach ($secondaryFindings as $idx => $candidate) {
            if (isset($matchedSecondaryIndexes[$idx])) {
                continue;
            }

            $candidate['source'] = 'ai_visual';
            $candidate['section'] = $verifyResult['page_type'] ?? $page->page_type ?? 'narrative';
            $candidate['evidences'] = [[
                'page' => $page->physical_page,
                'quote' => $candidate['quote'] ?? '',
                'bbox' => $candidate['bbox'] ?? null,
            ]];
            $candidate['agreement'] = 'single_agent';
            $candidate['agent_ids'] = [$verifierAgentId];

            $this->writer->store($page->publication, $candidate);
            $novelCount++;
        }

        // 3. Inspect table alignment specifically (Left, Right, Center rules)
        $alignmentIssues = $this->checkTableAlignmentSpecifics($page, $verifyResult);
        foreach ($alignmentIssues as $alignIssue) {
            $alignIssue['source'] = 'ai_visual';
            $alignIssue['section'] = 'table';
            $alignIssue['agreement'] = 'single_agent';
            $alignIssue['agent_ids'] = [$verifierAgentId];
            $this->writer->store($page->publication, $alignIssue);
            $novelCount++;
        }

        $agreementSummary = [
            'primary_agent_id' => $primaryAgentId,
            'verifier_agent_id' => $verifierAgentId,
            'primary_findings_count' => $primaryFindings->count(),
            'secondary_findings_count' => count($secondaryFindings),
            'confirmed_count' => $confirmedCount,
            'single_agent_count' => max(0, $primaryFindings->count() - $confirmedCount) + $novelCount,
            'novel_by_verifier_count' => $novelCount,
            'table_alignment_checked' => true,
            'page_type_match' => ($page->page_type === ($verifyResult['page_type'] ?? null)),
        ];

        return PageVerification::updateOrCreate(
            ['publication_page_id' => $page->id],
            [
                'ai_agent_id' => $verifierAgentId,
                'status' => 'completed',
                'result' => $verifyResult,
                'agreement' => $agreementSummary,
                'error' => null,
            ]
        );
    }

    /**
     * Inspect subtle visual alignment rules in tables.
     * Stub/labels must be left-aligned, numbers right-aligned, codes centered.
     *
     * @param  array<string, mixed>  $result
     * @return array<int, array<string, mixed>>
     */
    private function checkTableAlignmentSpecifics(PublicationPage $page, array $result): array
    {
        $findings = [];
        $text = $result['text'] ?? '';
        $tables = $result['inventory']['tables'] ?? [];

        // Check structured table column alignment anomalies
        foreach ($tables as $tbl) {
            foreach ($tbl['columns'] ?? [] as $col) {
                $align = strtolower($col['alignment'] ?? '');
                $lbl = $col['label'] ?? '';
                if (($align === 'left' && preg_match('/(jumlah|total|angka|luas|populasi|penduduk|persen|nilai)/i', $lbl)) ||
                    ($align === 'right' && preg_match('/(nama|kecamatan|kelurahan|kabupaten|desa|provinsi|wilayah|uraian|indikator)/i', $lbl))) {
                    $findings[] = [
                        'rule_code' => 'TABLE.FORMAT',
                        'severity' => 'wajib',
                        'observed' => "Ditemukan ketidaksesuaian perataan teks/angka pada kolom '{$lbl}' (alignment: {$align}) di tabel halaman {$page->physical_page}.",
                        'expected' => 'Kolom teks/label wajib rata kiri, kolom angka statistik wajib rata kanan, dan simbol/kode wilayah rata tengah.',
                        'recommendation' => 'Ubah perataan (alignment) sel tabel di InDesign/Word: angka rata kanan, teks rata kiri, simbol rata tengah.',
                        'evidences' => [[
                            'page' => $page->physical_page,
                            'quote' => $lbl,
                            'bbox' => null,
                        ]],
                    ];
                }
            }
        }

        // Check if there are alignment issues explicitly mentioned in text
        if (preg_match('/(rata\s+(kiri|kanan|tengah)|alignment|penjajaran|kolom\s+angka.*rata\s+kiri)/i', $text, $matches)) {
            $findings[] = [
                'rule_code' => 'TABLE.FORMAT',
                'severity' => 'wajib',
                'observed' => 'Ditemukan ketidaksesuaian alignment kolom pada tabel halaman '.$page->physical_page.': '.$matches[0].'.',
                'expected' => 'Kolom teks/label wajib rata kiri, kolom angka statistik wajib rata kanan, dan simbol/kode wilayah rata tengah.',
                'recommendation' => 'Ubah perataan (alignment) sel tabel di InDesign/Word: angka rata kanan, teks rata kiri, simbol rata tengah.',
                'evidences' => [[
                    'page' => $page->physical_page,
                    'quote' => mb_substr($matches[0], 0, 100),
                    'bbox' => null,
                ]],
            ];
        }

        return $findings;
    }
}
