<?php

declare(strict_types=1);

namespace App\Audit;

use App\Models\Finding;
use App\Models\Publication;
use Illuminate\Support\Facades\DB;

class FindingWriter
{
    public function store(Publication $publication, array $candidate): Finding
    {
        $candidate['evidences'] = array_map(function (array $evidence): array {
            $evidence['physical_page'] = $evidence['physical_page'] ?? $evidence['page'];
            unset($evidence['page']);

            return $evidence;
        }, $candidate['evidences']);
        $fingerprint = hash('sha256', $candidate['rule_code'].'|'.mb_strtolower(trim($candidate['observed'])).'|'.trim($candidate['expected']).'|'.($candidate['source'] ?? 'ai_visual'));

        return DB::transaction(function () use ($publication, $candidate, $fingerprint): Finding {
            $finding = Finding::firstOrCreate(['publication_id' => $publication->id, 'fingerprint' => $fingerprint], [
                'rule_code' => $candidate['rule_code'], 'section' => $candidate['section'] ?? 'Pemeriksaan isi dan layout',
                'severity' => $candidate['confidence'] < .80 ? 'verifikasi' : $candidate['severity'],
                'source' => $candidate['source'] ?? 'ai_visual', 'confidence' => $candidate['confidence'],
                'observed' => $candidate['observed'], 'expected' => $candidate['expected'], 'recommendation' => $candidate['recommendation'],
                'evidences' => $candidate['evidences'],
            ]);
            $locked = Finding::whereKey($finding->id)->lockForUpdate()->firstOrFail();
            $evidences = [...$locked->evidences, ...$candidate['evidences']];
            $unique = [];
            foreach ($evidences as $evidence) {
                $unique[hash('sha256', json_encode($evidence))] = $evidence;
            }
            $locked->update(['evidences' => array_values($unique)]);

            return $locked;
        });
    }
}
