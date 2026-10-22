<?php

declare(strict_types=1);

namespace App\Audit\Text;

class ArtifactSuppressor
{
    /**
     * Known kerning and extraction fragments that must not be flagged as spelling errors.
     *
     * @var array<int, string>
     */
    protected array $kerningArtifacts = [
        'hor ticulture',
        'transpor tasi',
        'transpor tation',
        'jakar ta',
        'per tanian',
        'kecama tan',
        'kabupa ten',
        'peme rintahan',
        'memberik an',
        'merupak an',
        'ditingk atkan',
    ];

    /**
     * Determine if a candidate finding should be suppressed because it is an artifact or false positive.
     *
     * @param  array<string, mixed>  $finding
     * @param  string|null  $normalizedText
     * @return array{suppress: bool, reason: string|null}
     */
    public function evaluate(array $finding, ?string $normalizedText = null): array
    {
        $rule = $finding['rule_code'] ?? '';
        $corpus = mb_strtolower(
            ($finding['observed'] ?? '').' '.
            ($finding['expected'] ?? '').' '.
            ($finding['recommendation'] ?? '')
        );

        // 1. Suppress kerning / space extraction artifacts
        if ($rule === 'LANGUAGE.EJAAN' || str_contains($corpus, 'terpisah spasi') || str_contains($corpus, 'spasi di tengah')) {
            foreach ($this->kerningArtifacts as $art) {
                if (str_contains($corpus, $art)) {
                    return [
                        'suppress' => true,
                        'reason' => "Artefak spasi/kerning ekstraksi PDF ({$art}) terdeteksi dan diabaikan.",
                    ];
                }
            }
        }

        // 2. Suppress "Layouters" on team/credits page
        if (str_contains($corpus, 'layouters')) {
            return [
                'suppress' => true,
                'reason' => "Istilah 'Layouters' adalah nomenklatur resmi Template KCDA BPS.",
            ];
        }

        // 3. Suppress Podes 2024 as outdated year in KCDA 2026
        if ((str_contains($corpus, 'podes') && str_contains($corpus, '2024')) && (str_contains($corpus, 'usang') || str_contains($corpus, 'kadaluarsa') || str_contains($corpus, 'tidak sesuai'))) {
            return [
                'suppress' => true,
                'reason' => 'Survei Podes 2024 adalah sumber berkala yang sah untuk publikasi 2026.',
            ];
        }

        // 4. Suppress minus sign / dash with superscript footnote (-³ is not negative 3)
        if (str_contains($corpus, '-³') || (str_contains($corpus, 'negatif') && str_contains($corpus, 'tiga'))) {
            return [
                'suppress' => true,
                'reason' => 'Simbol -³ adalah tanda strip (nihil) dengan superskrip catatan kaki, bukan angka negatif.',
            ];
        }

        return [
            'suppress' => false,
            'reason' => null,
        ];
    }

    /**
     * Check if finding is suppressed.
     *
     * @param  array<string, mixed>  $finding
     */
    public function shouldSuppress(array $finding, ?string $normalizedText = null): bool
    {
        return $this->evaluate($finding, $normalizedText)['suppress'];
    }
}
