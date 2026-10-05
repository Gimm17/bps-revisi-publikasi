<?php

declare(strict_types=1);

namespace App\Audit;

class EvaluationScorer
{
    public function score(array $gold, array $actual): array
    {
        if (($gold['adjudicated'] ?? false) !== true || empty($gold['expected'])) {
            throw new \InvalidArgumentException('Label gold belum diadjudikasi manusia atau kosong; akurasi belum dapat dihitung.');
        }
        $expected = collect($gold['expected'])->keyBy('id');
        $predicted = collect($actual)->keyBy('id');
        $seenGold = [];
        $seenActual = [];
        $tp = 0;
        foreach ($gold['matches'] ?? [] as $match) {
            $g = $expected->get($match['gold_id']);
            $a = $predicted->get($match['finding_id']);
            if (! $g || ! $a || isset($seenGold[$g['id']]) || isset($seenActual[$a['id']])) {
                throw new \InvalidArgumentException('Pasangan adjudikasi tidak valid atau ganda.');
            }
            if (isset($g['rule_code']) && $g['rule_code'] !== $a['rule_code']) {
                throw new \InvalidArgumentException('Kode aturan pasangan tidak cocok.');
            }
            if (isset($g['physical_page']) && ! collect($a['evidences'])->contains(fn ($e) => $e['physical_page'] === $g['physical_page'])) {
                throw new \InvalidArgumentException('Halaman bukti pasangan tidak cocok.');
            }
            $seenGold[$g['id']] = true;
            $seenActual[$a['id']] = true;
            if ($g['severity'] === 'wajib' && $a['severity'] === 'wajib') {
                $tp++;
            }
        }
        $mandatoryGold = $expected->where('severity', 'wajib')->count();
        $mandatoryActual = $predicted->where('severity', 'wajib')->count();
        $recall = $mandatoryGold ? round($tp / $mandatoryGold * 100, 2) : null;
        $precision = $mandatoryActual ? round($tp / $mandatoryActual * 100, 2) : null;
        $fp = $mandatoryActual ? round(($mandatoryActual - $tp) / $mandatoryActual * 100, 2) : null;

        return ['mandatory_true_positive' => $tp, 'mandatory_false_negative' => $mandatoryGold - $tp, 'mandatory_false_positive' => $mandatoryActual - $tp, 'mandatory_recall_percent' => $recall, 'mandatory_precision_percent' => $precision, 'mandatory_false_positive_percent' => $fp, 'accuracy_gate_passed' => $recall !== null && $recall >= 95 && $fp !== null && $fp <= 10];
    }
}
