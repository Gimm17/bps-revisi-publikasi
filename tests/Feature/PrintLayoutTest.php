<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\PageAnatomyIndexer;
use App\Audit\PrintLayoutChecker;
use Tests\Support\PublicationFixtures;
use Tests\TestCase;

class PrintLayoutTest extends TestCase
{
    use PublicationFixtures;

    public function test_odd_arabic_content_requires_two_blanks_not_added_to_catalogue(): void
    {
        $indexer = new PageAnatomyIndexer;
        $checker = new PrintLayoutChecker;

        // Roman xxi (21) + 1 blank = xxii (22)
        // Arabic 91 (odd) + 2 trailing blank pages = compliant
        $pages = [];
        $pages[] = $this->pageResult(1, ['page_type' => 'front_cover']);
        $pages[] = $this->pageResult(2, ['page_type' => 'title', 'printed_page' => null]);
        $pages[] = $this->pageResult(3, ['page_type' => 'catalogue', 'printed_page' => 'ii']);
        $pages[] = $this->pageResult(4, ['page_type' => 'preface', 'printed_page' => 'xxi']);
        $pages[] = $this->pageResult(5, ['page_type' => 'blank', 'printed_page' => null]); // Roman trailing blank
        $pages[] = $this->pageResult(6, ['page_type' => 'narrative', 'printed_page' => '1', 'text' => 'Content 1']);
        $pages[] = $this->pageResult(7, ['page_type' => 'narrative', 'printed_page' => '91', 'text' => 'Final Content 91']);
        $pages[] = $this->pageResult(8, ['page_type' => 'blank', 'printed_page' => null, 'is_pure_white' => true]);
        $pages[] = $this->pageResult(9, ['page_type' => 'blank', 'printed_page' => null, 'is_pure_white' => true]);
        $pages[] = $this->pageResult(10, ['page_type' => 'back_cover']);

        $anatomy = $indexer->build($pages);
        $result = $checker->check($anatomy);

        $this->assertSame('conform', $result['decision']);
        $this->assertSame('xxii+91 halaman/pages', $result['expected']['catalogue_page_count']);
        $this->assertSame(2, $result['expected']['trailing_white_pages']);
        $this->assertSame(2, $result['actual']['trailing_white_pages']);
    }

    public function test_even_arabic_content_requires_one_blank(): void
    {
        $indexer = new PageAnatomyIndexer;
        $checker = new PrintLayoutChecker;

        $pages = [];
        $pages[] = $this->pageResult(1, ['page_type' => 'front_cover']);
        $pages[] = $this->pageResult(2, ['page_type' => 'title']);
        $pages[] = $this->pageResult(3, ['page_type' => 'catalogue', 'printed_page' => 'ii']);
        $pages[] = $this->pageResult(4, ['page_type' => 'narrative', 'printed_page' => '92', 'text' => 'Final Content 92']);
        $pages[] = $this->pageResult(5, ['page_type' => 'blank', 'printed_page' => null, 'is_pure_white' => true]);
        $pages[] = $this->pageResult(6, ['page_type' => 'back_cover']);

        $anatomy = $indexer->build($pages);
        $result = $checker->check($anatomy);

        $this->assertSame(1, $result['expected']['trailing_white_pages']);
        $this->assertSame(1, $result['actual']['trailing_white_pages']);
        $this->assertSame('conform', $result['decision']);
    }

    public function test_odd_roman_end_is_completed_to_even(): void
    {
        $indexer = new PageAnatomyIndexer;
        $checker = new PrintLayoutChecker;

        // Roman ends at xxi (odd) WITHOUT a blank page
        $pages = [];
        $pages[] = $this->pageResult(1, ['page_type' => 'front_cover']);
        $pages[] = $this->pageResult(2, ['page_type' => 'title']);
        $pages[] = $this->pageResult(3, ['page_type' => 'preface', 'printed_page' => 'xxi']);
        $pages[] = $this->pageResult(4, ['page_type' => 'narrative', 'printed_page' => '1', 'text' => 'Bab 1']);

        $anatomy = $indexer->build($pages);
        $result = $checker->check($anatomy);

        $this->assertSame('nonconform', $result['decision']);
        $this->assertNotEmpty($result['findings']);
        $codes = array_column($result['findings'], 'rule_code');
        $this->assertContains('PRINT.ROMAN_END', $codes);
    }

    public function test_ornamented_tail_is_not_pure_blank(): void
    {
        $indexer = new PageAnatomyIndexer;
        $checker = new PrintLayoutChecker;

        // Arabic 91 with 1 pure blank + 1 ornamented page (not pure white)
        $pages = [];
        $pages[] = $this->pageResult(1, ['page_type' => 'front_cover']);
        $pages[] = $this->pageResult(2, ['page_type' => 'catalogue', 'printed_page' => 'ii']);
        $pages[] = $this->pageResult(3, ['page_type' => 'narrative', 'printed_page' => '91', 'text' => 'Akhir']);
        $pages[] = $this->pageResult(4, ['page_type' => 'blank', 'printed_page' => null, 'is_pure_white' => true]);
        $pages[] = $this->pageResult(5, ['page_type' => 'blank', 'printed_page' => null, 'is_pure_white' => false, 'has_ornament' => true]);
        $pages[] = $this->pageResult(6, ['page_type' => 'back_cover']);

        $anatomy = $indexer->build($pages);
        $result = $checker->check($anatomy);

        $this->assertSame(2, $result['expected']['trailing_white_pages']);
        $this->assertSame(1, $result['actual']['trailing_white_pages']); // only 1 is pure white
        $this->assertSame('nonconform', $result['decision']);
    }
}
