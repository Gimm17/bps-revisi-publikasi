<?php

declare(strict_types=1);

namespace App\Audit;

use InvalidArgumentException;

class EvidenceValidator
{
    public function normalize(array $evidence): array
    {
        $kind = $evidence['kind'] ?? (isset($evidence['reference_id']) ? 'reference_artifact' : 'publication_page');

        if ($kind === 'reference_artifact') {
            if (empty($evidence['reference_id'])) {
                throw new InvalidArgumentException('Reference evidence requires reference_id.');
            }
            $normalized = [
                'kind' => 'reference_artifact',
                'reference_id' => (string) $evidence['reference_id'],
                'artifact_id' => (string) ($evidence['artifact_id'] ?? ''),
                'artifact_page' => isset($evidence['artifact_page']) && is_numeric($evidence['artifact_page']) ? (int) $evidence['artifact_page'] : null,
                'source_url' => isset($evidence['source_url']) ? (string) $evidence['source_url'] : null,
                'provenance' => in_array($evidence['provenance'] ?? '', ['official_browser', 'user_upload'], true) ? $evidence['provenance'] : 'official_browser',
                'quote' => isset($evidence['quote']) ? (string) $evidence['quote'] : null,
            ];
            if ($normalized['artifact_page'] !== null && $normalized['artifact_page'] < 1) {
                throw new InvalidArgumentException('Artifact page must be at least 1.');
            }

            return $normalized;
        }

        $page = $evidence['physical_page'] ?? $evidence['page'] ?? null;
        if (! is_numeric($page) || (int) $page < 1) {
            throw new InvalidArgumentException('Physical page must be a positive integer greater than zero.');
        }

        $normalized = [
            'kind' => 'publication_page',
            'physical_page' => (int) $page,
            'printed_page' => isset($evidence['printed_page']) ? (string) $evidence['printed_page'] : null,
            'quote' => isset($evidence['quote']) ? (string) $evidence['quote'] : null,
            'bbox' => $evidence['bbox'] ?? null,
        ];

        if ($normalized['bbox'] !== null) {
            $this->validateBbox($normalized['bbox']);
        }

        return $normalized;
    }

    public function validateBbox(array $bbox): void
    {
        if (count($bbox) !== 4 || array_keys($bbox) !== [0, 1, 2, 3]) {
            throw new InvalidArgumentException('Format koordinat bbox tidak valid.');
        }
        foreach ($bbox as $val) {
            if (! is_numeric($val) || $val < 0 || $val > 1) {
                throw new InvalidArgumentException('Koordinat bbox harus antara 0 dan 1.');
            }
        }
        if ($bbox[0] >= $bbox[2] || $bbox[1] >= $bbox[3]) {
            throw new InvalidArgumentException('Koordinat bbox tidak valid (min >= max).');
        }
    }
}
