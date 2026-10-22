<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Audit\ChapterMapBuilder;
use PHPUnit\Framework\TestCase;

class ChapterMapBuilderTest extends TestCase
{
    public function test_builds_chapter_map_from_divider_pages(): void
    {
        $builder = new ChapterMapBuilder;

        $pages = [
            ['physical_page' => 1, 'page_type' => 'front_cover', 'result' => []],
            ['physical_page' => 2, 'page_type' => 'title', 'result' => []],
            ['physical_page' => 3, 'page_type' => 'catalogue', 'result' => []],
            ['physical_page' => 4, 'page_type' => 'contents', 'result' => []],
            ['physical_page' => 10, 'page_type' => 'chapter', 'result' => ['inventory' => ['chapter_title' => 'GEOGRAFI / Geography']]],
            ['physical_page' => 11, 'page_type' => 'narrative', 'result' => []],
            ['physical_page' => 15, 'page_type' => 'table', 'result' => []],
            ['physical_page' => 20, 'page_type' => 'chapter', 'result' => ['inventory' => ['chapter_title' => 'PEMERINTAHAN / Government']]],
            ['physical_page' => 21, 'page_type' => 'table', 'result' => []],
            ['physical_page' => 30, 'page_type' => 'bibliography', 'result' => []],
            ['physical_page' => 31, 'page_type' => 'back_cover', 'result' => []],
        ];

        $map = $builder->build($pages);

        $this->assertSame('chapter_dividers', $map['source']);
        $this->assertCount(2, $map['chapters']);

        $this->assertSame('Bab 1 Geografi', $map['chapters'][0]['label']);
        $this->assertSame(10, $map['chapters'][0]['physical_start']);
        $this->assertSame(19, $map['chapters'][0]['physical_end']);

        $this->assertSame('Bab 2 Pemerintahan', $map['chapters'][1]['label']);
        $this->assertSame(20, $map['chapters'][1]['physical_start']);
        $this->assertSame(29, $map['chapters'][1]['physical_end']);

        // Test page resolution
        $ch1 = $builder->resolveChapterForPage($map, 15);
        $this->assertNotNull($ch1);
        $this->assertSame('Bab 1 Geografi', $ch1['label']);

        $ch2 = $builder->resolveChapterForPage($map, 25);
        $this->assertNotNull($ch2);
        $this->assertSame('Bab 2 Pemerintahan', $ch2['label']);

        $chOutside = $builder->resolveChapterForPage($map, 2);
        $this->assertNull($chOutside);

        $chBack = $builder->resolveChapterForPage($map, 30);
        $this->assertNull($chBack);
    }

    public function test_resolves_from_ampana_kota_snapshot_fixture(): void
    {
        $fixturePath = dirname(__DIR__, 2) . '/tests/Fixtures/ampana-kota-2026/pages.json';
        if (! file_exists($fixturePath)) {
            $this->markTestSkipped('Ampana Kota fixture pages.json not found');
        }

        $pages = json_decode(file_get_contents($fixturePath), true);
        $builder = new ChapterMapBuilder;
        $map = $builder->build($pages);

        $this->assertSame('chapter_dividers', $map['source']);
        $this->assertCount(7, $map['chapters']);

        $labels = array_column($map['chapters'], 'label');
        $this->assertSame([
            'Bab 1 Geografi',
            'Bab 2 Pemerintahan',
            'Bab 3 Penduduk',
            'Bab 4 Sosial dan Kesejahteraan Rakyat',
            'Bab 5 Pertanian',
            'Bab 6 Pariwisata, Transportasi, dan Komunikasi',
            'Bab 7 Perbankan, Koperasi, dan Perdagangan',
        ], $labels);
    }
}
