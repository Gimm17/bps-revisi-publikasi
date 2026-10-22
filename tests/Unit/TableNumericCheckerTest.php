<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Audit\Numeric\TableNumericChecker;
use PHPUnit\Framework\TestCase;

class TableNumericCheckerTest extends TestCase
{
    public function test_detects_row_total_discrepancy(): void
    {
        $checker = new TableNumericChecker;

        $table = [
            'number' => '2.1.2',
            'title' => 'Jumlah Satuan Lingkungan Setempat',
            'headers' => ['Desa/Kelurahan', 'Jumlah SLS'],
            'rows' => [
                ['Sansarino', '9'],
                ['Malotong', '8'],
                ['Bailo', '10'],
                ['Ampana', '12'],
                ['Padang Tumbuo', '10'],
                ['Buntongi', '6'],
                ['Bonerato', '6'],
                ['Bailo Baru', '7'],
                ['Labiabae', '7'],
                ['Saluaba', '6'],
                ['Ampana Kota', '72'], // Sum of above is 81!
            ],
        ];

        $findings = $checker->checkTable($table, 42);

        $this->assertCount(1, $findings);
        $this->assertSame('TABLE.TOTAL', $findings[0]['rule_code']);
        $this->assertStringContainsString('72', $findings[0]['observed']);
        $this->assertStringContainsString('81', $findings[0]['observed']);
    }

    public function test_detects_column_total_discrepancy(): void
    {
        $checker = new TableNumericChecker;

        $table = [
            'number' => '4.1.3',
            'title' => 'Jumlah Guru Menurut Tingkat Pendidikan',
            'headers' => [
                'Tingkat Pendidikan',
                'Negeri 2024/2025',
                'Negeri 2025/2026',
                'Swasta 2024/2025',
                'Swasta 2025/2026',
                'Jumlah 2024/2025',
                'Jumlah 2025/2026',
            ],
            'rows' => [
                ['Taman Kanak-Kanak (TK)', '4', '4', '29', '59', '33', '59'], // 4 + 59 = 63 != 59!
                ['Sekolah Dasar (SD)', '181', '180', '3', '7', '184', '187'], // 180 + 7 = 187 == 187 (correct)
            ],
        ];

        $findings = $checker->checkTable($table, 72);

        $this->assertCount(1, $findings);
        $this->assertSame('TABLE.TOTAL', $findings[0]['rule_code']);
        $this->assertStringContainsString('59', $findings[0]['observed']);
        $this->assertStringContainsString('63', $findings[0]['observed']);
    }

    public function test_runs_on_ampana_kota_fixture(): void
    {
        $fixturePath = dirname(__DIR__, 2).'/tests/Fixtures/ampana-kota-2026/pages.json';
        if (! file_exists($fixturePath)) {
            $this->markTestSkipped('Ampana Kota fixture pages.json not found');
        }

        $pages = json_decode(file_get_contents($fixturePath), true);
        $checker = new TableNumericChecker;
        $findings = $checker->run($pages);

        $observedAll = implode(' ', array_column($findings, 'observed'));

        // Check that SLS (81 vs 72) is caught
        $this->assertStringContainsString('81', $observedAll);

        // Check that Guru TK (63 vs 59) is caught
        $this->assertStringContainsString('63', $observedAll);

        // Check that Saluaba sex ratio is caught
        $this->assertStringContainsString('Saluaba', $observedAll);
    }
}
