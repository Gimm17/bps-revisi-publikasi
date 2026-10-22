<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Audit\FindingConsolidator;
use App\Models\Finding;
use PHPUnit\Framework\TestCase;

class FindingConsolidatorTest extends TestCase
{
    public function test_consolidates_running_title_when_three_or_more(): void
    {
        $consolidator = new FindingConsolidator;

        $f1 = new Finding([
            'rule_code' => 'RUNNING.TITLE',
            'severity' => 'verifikasi',
            'review_status' => 'unreviewed',
            'observed' => 'Running title tidak tercantum pada halaman 25',
            'evidences' => [['physical_page' => 25]],
        ]);
        $f1->id = 101;

        $f2 = new Finding([
            'rule_code' => 'RUNNING.TITLE',
            'severity' => 'verifikasi',
            'review_status' => 'unreviewed',
            'observed' => 'Running title tidak tercantum pada halaman 27',
            'evidences' => [['physical_page' => 27]],
        ]);
        $f2->id = 102;

        $f3 = new Finding([
            'rule_code' => 'RUNNING.TITLE',
            'severity' => 'verifikasi',
            'review_status' => 'unreviewed',
            'observed' => 'Running title tidak tercantum pada halaman 29',
            'evidences' => [['physical_page' => 29]],
        ]);
        $f3->id = 103;

        $res = $consolidator->consolidate([$f1, $f2, $f3]);

        $this->assertCount(1, $res);
        $this->assertSame('RUNNING.TITLE', $res[0]->rule_code);
        $this->assertStringContainsString('3 halaman ganjil', $res[0]->observed);
        $this->assertSame([101, 102, 103], $res[0]->consolidated_ids);
    }

    public function test_consolidates_table_symbol_hyphen_when_three_or_more(): void
    {
        $consolidator = new FindingConsolidator;

        $items = [];
        for ($i = 1; $i <= 4; $i++) {
            $f = new Finding([
                'rule_code' => 'TABLE.SYMBOL',
                'severity' => 'saran',
                'review_status' => 'unreviewed',
                'observed' => "Pada Tabel 1.{$i}, terdapat tanda hubung (-) untuk nilai nol.",
                'evidences' => [['physical_page' => 30 + $i]],
            ]);
            $f->id = 200 + $i;
            $items[] = $f;
        }

        $res = $consolidator->consolidate($items);

        $this->assertCount(1, $res);
        $this->assertSame('TABLE.SYMBOL', $res[0]->rule_code);
        $this->assertStringContainsString('Tanda hubung (-) digunakan untuk menyatakan nilai nihil pada 4 butir', $res[0]->observed);
        $this->assertSame([201, 202, 203, 204], $res[0]->consolidated_ids);
    }

    public function test_does_not_consolidate_across_different_review_statuses(): void
    {
        $consolidator = new FindingConsolidator;

        $f1 = new Finding([
            'rule_code' => 'RUNNING.TITLE',
            'severity' => 'verifikasi',
            'review_status' => 'unreviewed',
            'observed' => 'Running title tidak tercantum pada halaman 25',
            'evidences' => [['physical_page' => 25]],
        ]);
        $f1->id = 101;

        $f2 = new Finding([
            'rule_code' => 'RUNNING.TITLE',
            'severity' => 'verifikasi',
            'review_status' => 'rejected', // Rejected by examiner!
            'observed' => 'Running title tidak tercantum pada halaman 27',
            'evidences' => [['physical_page' => 27]],
        ]);
        $f2->id = 102;

        $f3 = new Finding([
            'rule_code' => 'RUNNING.TITLE',
            'severity' => 'verifikasi',
            'review_status' => 'unreviewed',
            'observed' => 'Running title tidak tercantum pada halaman 29',
            'evidences' => [['physical_page' => 29]],
        ]);
        $f3->id = 103;

        $res = $consolidator->consolidate([$f1, $f2, $f3]);

        // f1 and f3 are only 2 unreviewed, f2 is 1 rejected. None should be consolidated!
        $this->assertCount(3, $res);
    }
}
