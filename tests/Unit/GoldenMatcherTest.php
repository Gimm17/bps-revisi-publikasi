<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Audit\Benchmark\GoldenMatcher;
use PHPUnit\Framework\TestCase;

class GoldenMatcherTest extends TestCase
{
    public function test_matches_by_rule_code_and_page(): void
    {
        $matcher = new GoldenMatcher;
        $golden = [
            'publication' => 'Test Pub',
            'items' => [
                [
                    'id' => 'G001',
                    'chapter' => 'Kover depan',
                    'kind' => 'must_find',
                    'weight' => 1.0,
                    'pages' => [1],
                    'rule_codes' => ['ISSN.COLON'],
                    'keywords_any' => ['ISSN :'],
                ],
            ],
        ];

        $findings = [
            [
                'rule_code' => 'ISSN.COLON',
                'observed' => 'Label ISSN : 1234-5678',
                'expected' => 'ISSN 1234-5678',
                'recommendation' => 'Hapus titik dua',
                'evidences' => [['physical_page' => 1, 'quote' => 'ISSN : 1234-5678']],
            ],
        ];

        $eval = $matcher->evaluate($golden, $findings);

        $this->assertSame(100.0, $eval['recall_percentage']);
        $this->assertSame(1, $eval['matched_must_find_count']);
        $this->assertSame(0, $eval['must_not_find_violations']);
    }

    public function test_page_distance_outside_tolerance_does_not_match(): void
    {
        $matcher = new GoldenMatcher;
        $golden = [
            'publication' => 'Test Pub',
            'items' => [
                [
                    'id' => 'G001',
                    'chapter' => 'Bab 1',
                    'kind' => 'must_find',
                    'weight' => 1.0,
                    'pages' => [10],
                    'rule_codes' => ['TABLE.TOTAL'],
                    'keywords_any' => ['Jumlah SLS'],
                ],
            ],
        ];

        // Finding is on page 50, far away from page 10
        $findings = [
            [
                'rule_code' => 'TABLE.TOTAL',
                'observed' => 'Jumlah SLS salah',
                'expected' => 'Benar',
                'recommendation' => 'Perbaiki',
                'evidences' => [['physical_page' => 50, 'quote' => 'SLS']],
            ],
        ];

        $eval = $matcher->evaluate($golden, $findings);

        $this->assertSame(0.0, $eval['recall_percentage']);
        $this->assertSame(1, $eval['unmatched_must_find_count']);
    }

    public function test_detects_must_not_find_violation(): void
    {
        $matcher = new GoldenMatcher;
        $golden = [
            'publication' => 'Test Pub',
            'items' => [
                [
                    'id' => 'N001',
                    'chapter' => 'Bab 5',
                    'kind' => 'must_not_find',
                    'weight' => 1.0,
                    'pages' => [89],
                    'rule_codes' => ['LANGUAGE.EJAAN'],
                    'keywords_any' => ['Hor ticulture'],
                    'text' => 'Artefak spasi layer PDF Hor ticulture dibatalkan',
                ],
            ],
        ];

        // Finding incorrectly flags 'Hor ticulture'
        $findings = [
            [
                'rule_code' => 'LANGUAGE.EJAAN',
                'observed' => 'Terdapat spasi pada Hor ticulture',
                'expected' => 'Horticulture',
                'recommendation' => 'Rapatkan',
                'evidences' => [['physical_page' => 89, 'quote' => 'Hor ticulture']],
            ],
        ];

        $eval = $matcher->evaluate($golden, $findings);

        $this->assertSame(1, $eval['must_not_find_violations']);
    }
}
