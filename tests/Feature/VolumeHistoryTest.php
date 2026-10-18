<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\VolumeHistoryChecker;
use Tests\TestCase;

class VolumeHistoryTest extends TestCase
{
    public function test_annual_volume_31_expects_32_not_publication_number(): void
    {
        $checker = new VolumeHistoryChecker;

        $currentIdentity = [
            'series' => 'kcda',
            'jurisdiction' => 'Ampana Kota',
            'year' => 2026,
            'frequency' => 'annual',
            'observed_volumes' => [
                'cover' => 31, // Target publication incorrectly printed Volume 31 instead of 32
                'title' => 31,
                'catalogue' => 31,
                'team' => null,
            ],
        ];

        $annualVolume31Reference = [
            'series_match' => true,
            'jurisdiction_match' => true,
            'year' => 2025,
            'frequency' => 'annual',
            'prior_volume' => 31,
            'identity_conflict' => false,
            'continuity_status' => 'continuous',
            'read_status' => 'readable',
            'evidences' => [
                [
                    'kind' => 'reference_artifact',
                    'reference_id' => 'ref-ampana-2025',
                    'artifact_id' => 'art-cover-2025',
                    'artifact_page' => 1,
                    'source_url' => 'https://tojointbps.go.id/publication/view/123',
                    'provenance' => 'official_browser',
                    'quote' => 'Kecamatan Ampana Kota Dalam Angka 2025 Volume 31',
                ],
            ],
        ];

        $result = $checker->compare($currentIdentity, $annualVolume31Reference);

        $this->assertSame(32, $result['expected_volume']);
        $this->assertSame('nonconform', $result['decision']);
        $this->assertNotEmpty($result['findings']);
        $this->assertSame('VOLUME.HISTORY', $result['findings'][0]['rule_code']);
    }

    public function test_unreadable_reference_never_invents_expected_volume(): void
    {
        $checker = new VolumeHistoryChecker;

        $currentIdentity = [
            'series' => 'kcda',
            'jurisdiction' => 'Ampana Kota',
            'year' => 2026,
            'frequency' => 'annual',
            'observed_volumes' => [
                'cover' => 31,
            ],
        ];

        $unreadableReference = [
            'series_match' => true,
            'jurisdiction_match' => true,
            'year' => 2025,
            'frequency' => 'annual',
            'prior_volume' => null,
            'identity_conflict' => false,
            'continuity_status' => 'unknown',
            'read_status' => 'unreadable',
            'evidences' => [],
        ];

        $result = $checker->compare($currentIdentity, $unreadableReference);

        $this->assertNull($result['expected_volume']);
        $this->assertSame('needs_verification', $result['decision']);
        $this->assertStringContainsString('volume edisi sebelumnya belum terbaca', $result['limitations'][0]);
    }

    public function test_series_reset_requires_verification(): void
    {
        $checker = new VolumeHistoryChecker;

        $currentIdentity = [
            'series' => 'kcda',
            'jurisdiction' => 'Ampana Kota',
            'year' => 2026,
            'frequency' => 'annual',
            'observed_volumes' => [
                'cover' => 1,
            ],
        ];

        $renumberedReference = [
            'series_match' => true,
            'jurisdiction_match' => true,
            'year' => 2025,
            'frequency' => 'annual',
            'prior_volume' => 31,
            'identity_conflict' => true, // Flagged renumbering or policy change
            'continuity_status' => 'renumbered',
            'read_status' => 'readable',
            'evidences' => [],
        ];

        $result = $checker->compare($currentIdentity, $renumberedReference);

        $this->assertSame('needs_verification', $result['decision']);
        $this->assertNull($result['expected_volume']);
    }
}
