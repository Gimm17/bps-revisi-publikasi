<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\EvidenceValidator;
use App\Audit\PageAnatomyIndexer;
use Tests\Support\PublicationFixtures;
use Tests\TestCase;

class PageAnatomyTest extends TestCase
{
    use PublicationFixtures;

    public function test_unnumbered_divider_is_counted_and_unknown_is_not_blank(): void
    {
        $indexer = new PageAnatomyIndexer;
        $pageResults = [
            $this->pageResult(1, ['page_type' => 'front_cover', 'printed_page' => null]),
            $this->pageResult(2, ['page_type' => 'title', 'printed_page' => null]),
            $this->pageResult(3, ['page_type' => 'catalogue', 'printed_page' => 'ii']),
            $this->pageResult(4, ['page_type' => 'contents', 'printed_page' => 'iii']),
            $this->pageResult(5, ['page_type' => 'chapter', 'printed_page' => null, 'text' => 'BAB 1']),
            $this->pageResult(6, ['page_type' => 'narrative', 'printed_page' => '1', 'text' => '']), // legacy empty text without v2 fields
        ];

        $index = $indexer->build($pageResults);

        $this->assertArrayHasKey('pages', $index);
        $this->assertArrayHasKey('anchors', $index);
        $this->assertArrayHasKey('uncertainties', $index);

        // Divider without printed number is counted and not pure white blank
        $this->assertSame(5, $index['pages'][4]['physical_page']);
        $this->assertSame('chapter', $index['pages'][4]['page_type']);
        $this->assertFalse($index['pages'][4]['blank_kind'] === 'pure_white');
        $this->assertTrue($index['pages'][4]['is_counted']);

        // Legacy page 6 with unknown content/blank state is null, not assumed blank
        $this->assertNull($index['pages'][5]['has_content']);
        $this->assertNull($index['pages'][5]['blank_kind']);
    }

    public function test_external_page_is_not_target_physical_page(): void
    {
        $validator = new EvidenceValidator;

        $referenceEvidence = $validator->normalize([
            'kind' => 'reference_artifact',
            'reference_id' => 'ref-123',
            'artifact_id' => 'art-456',
            'artifact_page' => 1,
            'source_url' => 'https://sulut.bps.go.id/publication/sample',
            'provenance' => 'official_browser',
            'quote' => 'Volume 31',
        ]);

        $this->assertSame('reference_artifact', $referenceEvidence['kind']);
        $this->assertSame('ref-123', $referenceEvidence['reference_id']);
        $this->assertSame(1, $referenceEvidence['artifact_page']);
        $this->assertArrayNotHasKey('physical_page', $referenceEvidence);
    }

    public function test_legacy_evidence_normalizes_without_fabricated_pages(): void
    {
        $validator = new EvidenceValidator;

        $legacyEvidence = $validator->normalize([
            'physical_page' => 3,
            'printed_page' => 'ii',
            'quote' => 'ISSN: 1234-5678',
            'bbox' => [0.1, 0.2, 0.3, 0.4],
        ]);

        $this->assertSame('publication_page', $legacyEvidence['kind']);
        $this->assertSame(3, $legacyEvidence['physical_page']);
        $this->assertSame('ii', $legacyEvidence['printed_page']);
        $this->assertSame([0.1, 0.2, 0.3, 0.4], $legacyEvidence['bbox']);

        // Invalid zero physical page is rejected, not fabricated
        $this->expectException(\InvalidArgumentException::class);
        $validator->normalize([
            'physical_page' => 0,
            'quote' => 'Invalid page zero',
        ]);
    }
}
