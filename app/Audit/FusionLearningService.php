<?php

declare(strict_types=1);

namespace App\Audit;

use App\Models\ExaminerLearning;
use App\Models\FusionCandidate;

class FusionLearningService
{
    /**
     * Memproses putusan adjudikasi dan langsung mengaktifkan pembelajaran otomatis.
     */
    public function ingestAdjudication(FusionCandidate $c, string $verdict, string $reason): void
    {
        $kind = match (true) {
            $c->engine === 'machine' && $verdict === 'confirm' => 'ai_blind_spot',
            $c->engine === 'machine' && $verdict === 'reject' => 'machine_false_positive',
            $c->engine === 'ai' && $verdict === 'confirm' => 'rule_gap',
            default => 'ai_hallucination',
        };

        // Kunci deterministik unik berdasarkan pola aturan dan potongan teks
        $normalizedSnippet = preg_replace('/\s+/', ' ', mb_substr(trim($c->observed), 0, 50));
        $key = 'fusion_'.hash('sha256', $kind.'|'.$c->rule_code.'|'.$normalizedSnippet);

        $learning = ExaminerLearning::firstOrNew(['key' => $key]);
        $learning->rule_code = $c->rule_code;
        $learning->kind = $kind;
        $learning->source = 'fusion_auto';
        $learning->topic = 'Auto-Learning Fusion: '.$c->rule_code;
        $learning->observed_example = $c->observed;
        $learning->reason = $reason;

        // Formula Instruksi yang diinjeksikan ke prompt AI berikutnya
        if ($kind === 'ai_blind_spot') {
            $learning->instruction = "PERHATIAN PEMERIKSAAN BPS: Jangan lewatkan aturan '{$c->rule_code}'. Contoh kasus nyata yang sempat terlewat: '{$c->observed}'. Pastikan dicek dengan cermat.";
        } elseif ($kind === 'ai_hallucination') {
            $learning->instruction = "HINDARI HALUSINASI: Jangan tandai teks '{$c->observed}' sebagai kesalahan '{$c->rule_code}' jika sesuai konteks resmi publikasi.";
        } elseif ($kind === 'machine_false_positive') {
            $learning->instruction = "CATATAN FORMAT: Teks '{$c->observed}' telah diverifikasi sah pada pedoman BPS, jangan dilaporkan berulang.";
        } else {
            $learning->instruction = "PENGUATAN ATURAN: Periksa secara konsisten aturan '{$c->rule_code}' untuk teks serupa: '{$c->observed}'.";
        }

        $learning->hits = ((int) $learning->hits) + 1;

        // KEPUTUSAN 1: OTOMATIS AKTIF (Langsung aktif jika hits >= 2 atau dari adjudikasi terkonfirmasi)
        if ($learning->hits >= 2 || $verdict === 'confirm') {
            $learning->status = 'active';
        } else {
            $learning->status = 'candidate';
        }

        $learning->save();
    }
}
