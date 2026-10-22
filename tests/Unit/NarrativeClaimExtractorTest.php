<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Audit\Claims\NarrativeClaimExtractor;
use PHPUnit\Framework\TestCase;

class NarrativeClaimExtractorTest extends TestCase
{
    public function test_detects_superlative_area_mismatch(): void
    {
        $extractor = new NarrativeClaimExtractor;

        $pages = [
            [
                'physical_page' => 28,
                'page_type' => 'narrative',
                'result' => [
                    'text' => 'Desa/Kelurahan yang memiliki luas wilayah terbesar adalah Desa Sansarino dengan persentase 31,54% dari luas wilayah Kecamatan Ampana Kota.',
                ],
            ],
            [
                'physical_page' => 31,
                'page_type' => 'table',
                'result' => [
                    'inventory' => [
                        'tables' => [
                            [
                                'number' => '1.1',
                                'title' => 'Luas Daerah Menurut Desa/Kelurahan di Kecamatan Ampana Kota, 2025',
                                'headers' => ['Desa/Kelurahan', 'Luas (km2)', 'Persentase (%)'],
                                'rows' => [
                                    ['Sansarino', '56,29', '31,54'],
                                    ['Malotong', '66,50', '37,26'],
                                    ['Ampana Kota', '178,47', '100,00'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $findings = $extractor->evaluate($pages);

        $this->assertCount(1, $findings);
        $this->assertSame('NARRATIVE.TABLE_MISMATCH', $findings[0]['rule_code']);
        $this->assertStringContainsString('Sansarino', $findings[0]['observed']);
        $this->assertStringContainsString('Malotong', $findings[0]['observed']);
        $this->assertCount(2, $findings[0]['evidences']);
    }

    public function test_detects_distance_destination_swap(): void
    {
        $extractor = new NarrativeClaimExtractor;

        $pages = [
            [
                'physical_page' => 28,
                'page_type' => 'narrative',
                'result' => [
                    'text' => 'Kelurahan Malotong merupakan daerah terjauh dengan jarak 12 km jika dilihat dari jarak terhadap kantor desa/kelurahan menuju pusat kecamatan.',
                ],
            ],
            [
                'physical_page' => 32,
                'page_type' => 'table',
                'result' => [
                    'inventory' => [
                        'tables' => [
                            [
                                'number' => '1.2',
                                'title' => 'Jarak ke Ibukota Kecamatan dan Ibukota Kabupaten Menurut Desa/Kelurahan',
                                'headers' => ['Desa/Kelurahan', 'Jarak ke Ibukota Kecamatan (km)', 'Jarak ke Ibukota Kabupaten (km)'],
                                'rows' => [
                                    ['Sansarino', '5', '1'],
                                    ['Malotong', '6', '12'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $findings = $extractor->evaluate($pages);

        $this->assertCount(1, $findings);
        $this->assertSame('NARRATIVE.TABLE_MISMATCH', $findings[0]['rule_code']);
        $this->assertStringContainsString('Malotong', $findings[0]['observed']);
        $this->assertStringContainsString('12', $findings[0]['observed']);
        $this->assertStringContainsString('6', $findings[0]['observed']);
    }

    public function test_evaluates_on_ampana_kota_fixture(): void
    {
        $fixturePath = dirname(__DIR__, 2).'/tests/Fixtures/ampana-kota-2026/pages.json';
        if (! file_exists($fixturePath)) {
            $this->markTestSkipped('Ampana Kota fixture pages.json not found');
        }

        $pages = json_decode(file_get_contents($fixturePath), true);
        $extractor = new NarrativeClaimExtractor;
        $findings = $extractor->evaluate($pages);

        $this->assertNotEmpty($findings);
        $observedAll = implode(' ', array_column($findings, 'observed'));

        $this->assertStringContainsString('Sansarino', $observedAll);
        $this->assertStringContainsString('Malotong', $observedAll);
        $this->assertStringContainsString('tomat', $observedAll);
    }
}
