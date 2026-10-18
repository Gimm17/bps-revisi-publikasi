<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\CrossChecker;
use App\Audit\PageAnatomyIndexer;
use App\Audit\PrintLayoutChecker;
use App\Audit\VolumeHistoryChecker;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PublicationFixtures;
use Tests\TestCase;

class RuleV2RegressionTest extends TestCase
{
    use PublicationFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_guideline_regressions_preserve_confirmed_exceptions(): void
    {
        $crossChecker = app(CrossChecker::class);

        // 1. Correct catalogue ISSN colon produces 0 findings for ISSN.COLON
        $cataloguePageResult = [
            'physical_page' => 3,
            'printed_page' => 'iii',
            'readability' => 'readable',
            'page_type' => 'catalogue',
            'text' => "Katalog BPS\nISSN: 1234-5678\nNo. Publikasi: 12345.6789",
            'inventory' => [],
            'identity' => [
                'issn' => 'ISSN: 1234-5678',
            ],
            'contents' => [],
            'findings' => [],
        ];

        $crossFindings = $crossChecker->run([$cataloguePageResult]);
        $findingsForCorrectCatalogueIssnColon = array_filter(
            $crossFindings,
            fn ($f) => $f['rule_code'] === 'ISSN.COLON'
        );
        $this->assertCount(0, $findingsForCorrectCatalogueIssnColon);

        // 2. Valid source year (e.g. Podes 2024) produces 0 findings
        $tablePageResult = [
            'physical_page' => 15,
            'printed_page' => '11',
            'readability' => 'readable',
            'page_type' => 'table',
            'text' => "Tabel 1.1 Jumlah Penduduk\nSumber: BPS, Potensi Desa (Podes) 2024",
            'inventory' => [
                'tables' => [
                    [
                        'number' => '1.1',
                        'title' => 'Jumlah Penduduk',
                        'source' => 'BPS, Potensi Desa (Podes) 2024',
                        'source_year' => '2024',
                    ],
                ],
            ],
            'identity' => [],
            'contents' => [],
            'findings' => [],
        ];
        $tableCrossFindings = $crossChecker->run([$tablePageResult]);
        $findingsForValidPodes2024SourceYear = array_filter(
            $tableCrossFindings,
            fn ($f) => in_array($f['rule_code'], ['SOURCE.YEAR', 'TABLE.SOURCE_YEAR'], true)
        );
        $this->assertCount(0, $findingsForValidPodes2024SourceYear);

        // 3. Allowed layouters label produces 0 findings
        $teamPageResult = [
            'physical_page' => 4,
            'printed_page' => 'iv',
            'readability' => 'readable',
            'page_type' => 'team',
            'text' => "Tim Penyusun\nTata Letak/Layout: Tim Produksi",
            'inventory' => [],
            'identity' => [
                'layouter' => 'Tata Letak/Layout: Tim Produksi',
            ],
            'contents' => [],
            'findings' => [],
        ];
        $teamCrossFindings = $crossChecker->run([$teamPageResult]);
        $findingsForAllowedLayoutersLabel = array_filter(
            $teamCrossFindings,
            fn ($f) => in_array($f['rule_code'], ['TEAM.LAYOUTER', 'LAYOOUTER.LABEL'], true)
        );
        $this->assertCount(0, $findingsForAllowedLayoutersLabel);

        // 4. Unreadable prior volume decision is strictly needs_verification
        $volumeChecker = app(VolumeHistoryChecker::class);
        $currentIdentity = [
            'volume' => 31,
            'year' => 2026,
            'title' => 'Kecamatan Ampana Kota Dalam Angka 2026',
        ];
        $unreadableReference = [
            'target_title' => 'Kecamatan Ampana Kota Dalam Angka 2025',
            'target_year' => 2025,
            'provenance' => 'official_browser',
            'access_status' => 'opened',
            'read_status' => 'unreadable',
            'metadata' => [],
        ];
        $unreadableResult = $volumeChecker->compare($currentIdentity, $unreadableReference);
        $unreadablePriorVolumeDecision = $unreadableResult['decision'];
        $this->assertSame('needs_verification', $unreadablePriorVolumeDecision);
    }

    public function test_print_layout_conformance_and_catalogue_formatting(): void
    {
        $indexer = new PageAnatomyIndexer;
        $checker = app(PrintLayoutChecker::class);

        // 91 Arabic pages (odd) with 2 trailing pure white blanks and xxi Roman completed to xxii
        $pagesOdd = [
            $this->pageResult(1, ['page_type' => 'front_cover']),
            $this->pageResult(2, ['page_type' => 'title', 'printed_page' => null]),
            $this->pageResult(3, ['page_type' => 'catalogue', 'printed_page' => 'ii']),
            $this->pageResult(4, ['page_type' => 'preface', 'printed_page' => 'xxi']),
            $this->pageResult(5, ['page_type' => 'blank', 'printed_page' => null]),
            $this->pageResult(6, ['page_type' => 'narrative', 'printed_page' => '1', 'text' => 'Content 1']),
            $this->pageResult(7, ['page_type' => 'narrative', 'printed_page' => '91', 'text' => 'Final Content 91']),
            $this->pageResult(8, ['page_type' => 'blank', 'printed_page' => null, 'is_pure_white' => true]),
            $this->pageResult(9, ['page_type' => 'blank', 'printed_page' => null, 'is_pure_white' => true]),
            $this->pageResult(10, ['page_type' => 'back_cover']),
        ];

        $anatomyOdd = $indexer->build($pagesOdd);
        $resOdd = $checker->check($anatomyOdd);
        $this->assertSame('conform', $resOdd['decision']);
        $this->assertSame('xxii+91 halaman/pages', $resOdd['expected']['catalogue_page_count']);
        $this->assertSame(2, $resOdd['expected']['trailing_white_pages']);

        // 92 Arabic pages (even) requires 1 trailing pure white blank
        $pagesEven = [
            $this->pageResult(1, ['page_type' => 'front_cover']),
            $this->pageResult(2, ['page_type' => 'title']),
            $this->pageResult(3, ['page_type' => 'catalogue', 'printed_page' => 'ii']),
            $this->pageResult(4, ['page_type' => 'narrative', 'printed_page' => '92', 'text' => 'Final Content 92']),
            $this->pageResult(5, ['page_type' => 'blank', 'printed_page' => null, 'is_pure_white' => true]),
            $this->pageResult(6, ['page_type' => 'back_cover']),
        ];

        $anatomyEven = $indexer->build($pagesEven);
        $resEven = $checker->check($anatomyEven);
        $this->assertSame('conform', $resEven['decision']);
        $this->assertSame(1, $resEven['expected']['trailing_white_pages']);
    }

    public function test_legacy_publication_remains_frozen_on_baseline_version(): void
    {
        $user = User::factory()->create();
        $pub = Publication::create([
            'owner_id' => $user->id,
            'title' => 'Publikasi Lama 2025',
            'region' => 'Ampana Kota',
            'year' => 2025,
            'kind' => 'kcda',
            'pdf_path' => 'dummy.pdf',
            'pdf_name' => 'dummy.pdf',
            'pdf_checksum' => hash('sha256', 'legacy-pdf'),
            'template_path' => 'template.xlsx',
            'template_name' => 'template.xlsx',
            'template_checksum' => hash('sha256', 'tpl'),
            'page_count' => 50,
            'manifest' => [],
            'rule_version' => 'bps-2026.10.05-v1',
            'status' => 'review_ready',
        ]);

        $this->assertSame('bps-2026.10.05-v1', $pub->rule_version);
        $this->assertSame(0, $pub->checkRuns()->count());
    }
}
