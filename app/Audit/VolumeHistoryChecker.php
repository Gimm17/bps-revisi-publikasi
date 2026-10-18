<?php

declare(strict_types=1);

namespace App\Audit;

class VolumeHistoryChecker
{
    /**
     * @param  array<string, mixed>  $currentIdentity
     * @param  array<string, mixed>  $reference
     * @return array{
     *   decision: 'conform'|'nonconform'|'needs_verification'|'not_applicable',
     *   expected_volume: ?int,
     *   components: array<string, mixed>,
     *   findings: array<int, array<string, mixed>>,
     *   evidences: array<int, array<string, mixed>>,
     *   limitations: array<int, string>
     * }
     */
    public function compare(array $currentIdentity, array $reference): array
    {
        $limitations = [];
        $findings = [];
        $components = [];
        $evidences = $reference['evidences'] ?? [];

        // Check reference readiness & read status
        $readStatus = $reference['read_status'] ?? 'unreadable';
        $priorVolume = isset($reference['prior_volume']) && is_numeric($reference['prior_volume'])
            ? (int) $reference['prior_volume']
            : null;

        if ($readStatus !== 'readable' || $priorVolume === null) {
            $limitations[] = 'Perlu verifikasi — volume edisi sebelumnya belum terbaca dari sumber rujukan resmi.';

            return [
                'decision' => 'needs_verification',
                'expected_volume' => null,
                'components' => [],
                'findings' => [],
                'evidences' => $evidences,
                'limitations' => $limitations,
            ];
        }

        // Check continuity or identity conflict
        $isConflict = (bool) ($reference['identity_conflict'] ?? false);
        $continuity = $reference['continuity_status'] ?? 'continuous';
        if ($isConflict || $continuity === 'renumbered') {
            $limitations[] = 'Perlu verifikasi — terdeteksi jeda terbit, penomoran ulang, atau perubahan kebijakan seri publikasi.';

            return [
                'decision' => 'needs_verification',
                'expected_volume' => null,
                'components' => [],
                'findings' => [],
                'evidences' => $evidences,
                'limitations' => $limitations,
            ];
        }

        // Calculate expected volume (Volume = Prior + 1 for regular annual publication)
        $expectedVolume = $priorVolume + 1;

        $observedVolumes = $currentIdentity['observed_volumes'] ?? [];
        $hasMismatch = false;

        $partNames = [
            'cover' => 'kover depan',
            'title' => 'halaman judul',
            'catalogue' => 'halaman katalog',
            'team' => 'halaman tim penyusun',
        ];

        foreach ($observedVolumes as $part => $obsVal) {
            $label = $partNames[$part] ?? $part;
            $components[$part] = [
                'observed' => $obsVal,
                'expected' => $expectedVolume,
                'match' => $obsVal === $expectedVolume,
            ];

            if ($obsVal !== null && $obsVal !== $expectedVolume) {
                $hasMismatch = true;
                $findings[] = [
                    'rule_code' => 'VOLUME.HISTORY',
                    'section' => 'Kesesuaian volume publikasi',
                    'severity' => 'wajib',
                    'source' => 'cross_check',
                    'confidence' => 0.95,
                    'observed' => "Tercetak Volume {$obsVal} pada {$label}.",
                    'expected' => "Volume {$expectedVolume} (berdasarkan edisi sebelumnya Volume {$priorVolume}).",
                    'recommendation' => "Sesuaikan penulisan volume pada {$label} menjadi Volume {$expectedVolume}.",
                    'evidences' => $evidences,
                ];
            }
        }

        return [
            'decision' => $hasMismatch ? 'nonconform' : 'conform',
            'expected_volume' => $expectedVolume,
            'components' => $components,
            'findings' => $findings,
            'evidences' => $evidences,
            'limitations' => $limitations,
        ];
    }
}
