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
     *   prior_volume: ?int,
     *   prior_year: ?int,
     *   reference_meta: array<string, mixed>,
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

        $currentYear = (int) ($currentIdentity['year'] ?? 2026);
        $priorYear = (int) ($reference['prior_year'] ?? ($currentYear - 1));
        $observedVolumes = $currentIdentity['observed_volumes'] ?? [];
        $pageMap = $currentIdentity['page_map'] ?? [];
        $referenceMeta = $reference['metadata'] ?? [];

        $partNames = [
            'cover' => 'kover depan',
            'title' => 'halaman judul',
            'catalogue' => 'halaman katalog',
            'team' => 'halaman tim penyusun',
        ];

        $readStatus = $reference['read_status'] ?? 'unreadable';
        $priorVolume = isset($reference['prior_volume']) && is_numeric($reference['prior_volume'])
            ? (int) $reference['prior_volume']
            : null;

        // If reference is not readable or priorVolume is null, still check internal consistency & populate components
        if ($readStatus !== 'readable' || $priorVolume === null) {
            $nonNullVolumes = array_values(array_filter($observedVolumes, fn ($v) => $v !== null));
            $uniqueVolumes = array_values(array_unique($nonNullVolumes));

            foreach ($observedVolumes as $part => $obsVal) {
                $components[$part] = [
                    'label' => ucfirst($partNames[$part] ?? $part),
                    'page' => $pageMap[$part] ?? null,
                    'observed' => $obsVal,
                    'expected' => null,
                    'match' => null,
                ];
            }

            // Internal inconsistency across pages in current publication (e.g., Cover Vol 32 vs Title Vol 17)
            if (count($uniqueVolumes) > 1) {
                $summaryParts = [];
                $internalEvidences = [];
                foreach ($observedVolumes as $part => $obsVal) {
                    if ($obsVal !== null) {
                        $label = $partNames[$part] ?? $part;
                        $pNum = $pageMap[$part] ?? 1;
                        $summaryParts[] = "{$label} (PDF hlm. {$pNum}) tertulis Volume {$obsVal}";
                        $internalEvidences[] = [
                            'page' => $pNum,
                            'physical_page' => $pNum,
                            'quote' => "Volume {$obsVal}, {$currentYear} pada {$label}",
                        ];
                    }
                }
                $findings[] = [
                    'rule_code' => 'VOLUME.HISTORY',
                    'section' => 'Kesesuaian volume publikasi',
                    'severity' => 'wajib',
                    'source' => 'cross_check',
                    'confidence' => 0.98,
                    'observed' => 'Inkonsistensi nomor volume antarhalaman: '.implode('; ', $summaryParts).'.',
                    'expected' => 'Nomor volume harus seragam pada kover depan, halaman judul, halaman katalog, dan halaman tim penyusun.',
                    'recommendation' => 'Samakan nomor volume pada seluruh halaman sesuai urutan volume edisi tahun sebelumnya ditambah 1.',
                    'evidences' => ! empty($internalEvidences) ? $internalEvidences : $evidences,
                ];
            }

            if (! empty($referenceMeta['title'])) {
                $limitations[] = "Publikasi rujukan '{$referenceMeta['title']}' ditemukan di BPS WebAPI, namun sampul edisi {$priorYear} tidak mencantumkan angka volume. Silakan masukkan angka volume tahun {$priorYear} pada kolom konfirmasi cepat.";
            } else {
                $limitations[] = 'Perlu verifikasi — volume edisi sebelumnya belum terbaca dari sumber rujukan resmi. Gunakan kolom konfirmasi cepat untuk memasukkan volume tahun lalu.';
            }

            return [
                'decision' => ! empty($findings) ? 'nonconform' : 'needs_verification',
                'expected_volume' => null,
                'prior_volume' => null,
                'prior_year' => $priorYear,
                'reference_meta' => $referenceMeta,
                'components' => $components,
                'findings' => $findings,
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
                'prior_volume' => $priorVolume,
                'prior_year' => $priorYear,
                'reference_meta' => $referenceMeta,
                'components' => [],
                'findings' => [],
                'evidences' => $evidences,
                'limitations' => $limitations,
            ];
        }

        // Calculate expected volume (Volume = Prior + 1 for regular annual publication)
        $expectedVolume = $priorVolume + 1;
        $hasMismatch = false;
        $anyObserved = ! empty(array_filter($observedVolumes, fn ($v) => $v !== null));

        foreach ($observedVolumes as $part => $obsVal) {
            $label = $partNames[$part] ?? $part;
            $pNum = $pageMap[$part] ?? null;

            $components[$part] = [
                'label' => ucfirst($label),
                'page' => $pNum,
                'observed' => $obsVal,
                'expected' => $expectedVolume,
                'match' => $obsVal === null ? null : ($obsVal === $expectedVolume),
            ];

            $itemEvidences = [];
            if ($pNum !== null) {
                $itemEvidences[] = [
                    'page' => $pNum,
                    'physical_page' => $pNum,
                    'quote' => $obsVal !== null
                        ? "Volume {$obsVal}, {$currentYear} pada {$label}"
                        : "Nomor volume belum tercantum pada {$label}",
                ];
            }
            foreach ($evidences as $refEv) {
                if (is_array($refEv)) {
                    $refEv['kind'] = $refEv['kind'] ?? 'reference_artifact';
                    $refEv['reference_id'] = (string) ($refEv['reference_id'] ?? ($referenceMeta['reference_id'] ?? 'ref-bps-api'));
                    $refEv['artifact_id'] = (string) ($refEv['artifact_id'] ?? ($refEv['id'] ?? 'cover_image'));
                    $itemEvidences[] = $refEv;
                }
            }

            if ($obsVal !== null && $obsVal !== $expectedVolume) {
                $hasMismatch = true;
                $findings[] = [
                    'rule_code' => 'VOLUME.HISTORY',
                    'section' => 'Kesesuaian volume publikasi',
                    'severity' => 'wajib',
                    'source' => 'cross_check',
                    'confidence' => 0.96,
                    'observed' => "Tercetak Volume {$obsVal} pada {$label}".($pNum ? " (PDF hlm. {$pNum})" : '').'.',
                    'expected' => "Volume {$expectedVolume}, {$currentYear} (berdasarkan edisi {$priorYear} Volume {$priorVolume}).",
                    'recommendation' => "Sesuaikan penulisan volume pada {$label}".($pNum ? " (PDF hlm. {$pNum})" : '')." menjadi Volume {$expectedVolume}, {$currentYear}.",
                    'evidences' => $itemEvidences,
                ];
            } elseif ($obsVal === null && in_array($part, ['cover', 'title'], true) && (! $anyObserved || ($observedVolumes['cover'] ?? null) !== ($observedVolumes['title'] ?? null))) {
                $hasMismatch = true;
                $findings[] = [
                    'rule_code' => 'VOLUME.HISTORY',
                    'section' => 'Kesesuaian volume publikasi',
                    'severity' => 'wajib',
                    'source' => 'cross_check',
                    'confidence' => 0.92,
                    'observed' => "Nomor volume belum tercantum pada {$label}".($pNum ? " (PDF hlm. {$pNum})" : '').'.',
                    'expected' => "Volume {$expectedVolume}, {$currentYear} (berdasarkan edisi {$priorYear} Volume {$priorVolume}).",
                    'recommendation' => "Cantumkan 'Volume {$expectedVolume}, {$currentYear}' pada {$label}".($pNum ? " (PDF hlm. {$pNum})" : '').'.',
                    'evidences' => $itemEvidences,
                ];
            }
        }

        return [
            'decision' => $hasMismatch ? 'nonconform' : 'conform',
            'expected_volume' => $expectedVolume,
            'prior_volume' => $priorVolume,
            'prior_year' => $priorYear,
            'reference_meta' => $referenceMeta,
            'components' => $components,
            'findings' => $findings,
            'evidences' => $evidences,
            'limitations' => $limitations,
        ];
    }
}
