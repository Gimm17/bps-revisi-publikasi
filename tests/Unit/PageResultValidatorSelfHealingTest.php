<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Audit\PageResultValidator;
use Tests\TestCase;

class PageResultValidatorSelfHealingTest extends TestCase
{
    private function basePageData(): array
    {
        return [
            'physical_page' => 1,
            'page_type' => 'narrative',
            'readability' => 'readable',
            'printed_page' => '1',
            'text' => 'Penduduk kecamatan berjumlah 50000 jiwa pada tahun 2026.',
            'inventory' => [
                'headings' => [],
                'tables' => [],
                'figures' => [],
                'header' => '',
                'footer' => '',
            ],
            'identity' => [],
            'contents' => [],
            'findings' => [],
        ];
    }

    public function test_normalizes_gemini_thousand_scale_bbox(): void
    {
        $validator = new PageResultValidator();
        $data = $this->basePageData();
        $data['findings'] = [
            [
                'rule_code' => 'BPS.FULL_NAME',
                'severity' => 'wajib',
                'confidence' => 0.95,
                'observed' => 'Penduduk',
                'expected' => 'Penduduk',
                'recommendation' => 'Periksa ejaan',
                'quote' => 'Penduduk',
                'bbox' => [100, 200, 300, 400], // 0..1000 integer scale!
            ],
        ];

        $validated = $validator->validate($data, 1);
        $this->assertEquals([0.1, 0.2, 0.3, 0.4], $validated['findings'][0]['bbox']);
    }

    public function test_swaps_inverted_bbox_coordinates(): void
    {
        $validator = new PageResultValidator();
        $data = $this->basePageData();
        $data['findings'] = [
            [
                'rule_code' => 'BPS.FULL_NAME',
                'severity' => 'wajib',
                'confidence' => 0.95,
                'observed' => 'Penduduk',
                'expected' => 'Penduduk',
                'recommendation' => 'Periksa ejaan',
                'quote' => 'Penduduk',
                'bbox' => [0.6, 0.8, 0.2, 0.3], // inverted min > max!
            ],
        ];

        $validated = $validator->validate($data, 1);
        $this->assertEquals([0.2, 0.3, 0.6, 0.8], $validated['findings'][0]['bbox']);
    }

    public function test_drops_degenerate_zero_area_bbox_when_quote_is_valid(): void
    {
        $validator = new PageResultValidator();
        $data = $this->basePageData();
        $data['findings'] = [
            [
                'rule_code' => 'BPS.FULL_NAME',
                'severity' => 'wajib',
                'confidence' => 0.95,
                'observed' => 'Penduduk',
                'expected' => 'Penduduk',
                'recommendation' => 'Periksa ejaan',
                'quote' => 'Penduduk',
                'bbox' => [0, 0, 0, 0], // zero area degenerate bbox!
            ],
        ];

        $validated = $validator->validate($data, 1);
        // Bbox should be gracefully sanitized to null and finding kept!
        $this->assertNull($validated['findings'][0]['bbox']);
        $this->assertEquals('Penduduk', $validated['findings'][0]['quote']);
    }

    public function test_drops_associative_malformed_bbox_when_quote_is_valid(): void
    {
        $validator = new PageResultValidator();
        $data = $this->basePageData();
        $data['findings'] = [
            [
                'rule_code' => 'BPS.FULL_NAME',
                'severity' => 'wajib',
                'confidence' => 0.95,
                'observed' => 'Penduduk',
                'expected' => 'Penduduk',
                'recommendation' => 'Periksa ejaan',
                'quote' => 'Penduduk',
                'bbox' => ['x' => 10, 'y' => 20], // completely invalid format!
            ],
        ];

        $validated = $validator->validate($data, 1);
        $this->assertNull($validated['findings'][0]['bbox']);
    }

    public function test_sanitizes_page_type_aliases(): void
    {
        $validator = new PageResultValidator();
        $data = $this->basePageData();
        $data['page_type'] = 'TABLES'; // plural & uppercase
        $validated = $validator->validate($data, 1);
        $this->assertEquals('table', $validated['page_type']);

        $data['page_type'] = 'table_of_contents';
        $validated = $validator->validate($data, 1);
        $this->assertEquals('contents', $validated['page_type']);

        $data['page_type'] = 'unknown_exotic_type';
        $validated = $validator->validate($data, 1);
        $this->assertEquals('narrative', $validated['page_type']);
    }
}
