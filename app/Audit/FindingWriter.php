<?php

declare(strict_types=1);

namespace App\Audit;

use App\Models\Finding;
use App\Models\Publication;
use Illuminate\Support\Facades\DB;

class FindingWriter
{
    public function __construct(private ?EvidenceValidator $validator = null)
    {
        $this->validator ??= new EvidenceValidator;
    }

    public function store(Publication $publication, array $candidate): Finding
    {
        $candidate['evidences'] = array_map(function (array $evidence): array {
            return $this->validator->normalize($evidence);
        }, $candidate['evidences'] ?? []);
        $fingerprint = hash('sha256', $candidate['rule_code'].'|'.mb_strtolower(trim($candidate['observed'])).'|'.trim($candidate['expected']).'|'.($candidate['source'] ?? 'ai_visual'));

        return DB::transaction(function () use ($publication, $candidate, $fingerprint): Finding {
            $finding = Finding::firstOrCreate(['publication_id' => $publication->id, 'fingerprint' => $fingerprint], [
                'check_run_id' => $candidate['check_run_id'] ?? null,
                'rule_code' => $candidate['rule_code'],
                'section' => $candidate['section'] ?? 'Pemeriksaan isi dan layout',
                'severity' => ($candidate['confidence'] ?? 1.0) < .80 ? 'verifikasi' : $candidate['severity'],
                'source' => $candidate['source'] ?? 'ai_visual',
                'detected_by' => $candidate['detected_by'] ?? (($candidate['source'] ?? '') === 'machine' ? 'machine' : 'ai'),
                'fusion_status' => $candidate['fusion_status'] ?? null,
                'confidence' => $candidate['confidence'] ?? 1.0,
                'observed' => $candidate['observed'],
                'expected' => $candidate['expected'],
                'recommendation' => $candidate['recommendation'],
                'evidences' => $candidate['evidences'],
                'agreement' => $candidate['agreement'] ?? null,
                'agent_ids' => $candidate['agent_ids'] ?? null,
            ]);
            $locked = Finding::whereKey($finding->id)->lockForUpdate()->firstOrFail();
            $evidences = [...$locked->evidences, ...$candidate['evidences']];
            $unique = [];
            foreach ($evidences as $evidence) {
                $unique[hash('sha256', json_encode($evidence))] = $evidence;
            }
            $updateData = ['evidences' => array_values($unique)];
            if (! empty($candidate['agent_ids'])) {
                $mergedAgentIds = array_values(array_unique(array_filter([
                    ...($locked->agent_ids ?? []),
                    ...$candidate['agent_ids'],
                ])));
                $updateData['agent_ids'] = $mergedAgentIds;
            }
            if (! empty($candidate['agreement'])) {
                $updateData['agreement'] = $candidate['agreement'];
            }
            $locked->update($updateData);

            return $locked;
        });
    }
}
