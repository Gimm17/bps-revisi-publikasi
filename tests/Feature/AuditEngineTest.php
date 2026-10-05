<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\CrossChecker;
use App\Audit\PageResultValidator;
use App\Audit\PdfPreparer;
use App\Audit\RuleEvaluator;
use Illuminate\Validation\ValidationException;
use Tests\Support\PublicationFixtures;
use Tests\TestCase;

class AuditEngineTest extends TestCase
{
    use PublicationFixtures;

    public function test_manifest_and_render_match_the_physical_pdf(): void
    {
        $pdf = $this->pdfFile(3);
        $service = app(PdfPreparer::class);
        $this->assertSame(3, $service->inspect($pdf->getPathname())['page_count']);
        $image = sys_get_temp_dir().'/audit-'.uniqid().'.jpg';
        try {
            $service->render($pdf->getPathname(), 2, $image, 72);
            $this->assertGreaterThan(1000, filesize($image));
            $this->assertSame('image/jpeg', mime_content_type($image));
        } finally {
            if (is_file($image)) {
                unlink($image);
            }
        }
    }

    public function test_wrong_page_cannot_be_accepted(): void
    {
        $this->expectException(ValidationException::class);
        app(PageResultValidator::class)->validate($this->pageResult(2), 1);
    }

    public function test_unknown_rules_cannot_be_accepted(): void
    {
        $this->expectException(ValidationException::class);
        app(PageResultValidator::class)->validate($this->pageResult(1, ['findings' => [[
            'rule_code' => 'INVENTED.RULE', 'severity' => 'wajib', 'confidence' => .95,
            'observed' => 'Kesalahan', 'expected' => 'Benar', 'recommendation' => 'Perbaiki',
            'quote' => 'Kesalahan', 'bbox' => null,
        ]]]), 1);
    }

    public function test_findings_without_evidence_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(PageResultValidator::class)->validate($this->pageResult(1, ['findings' => [[
            'rule_code' => 'VISUAL.LAYOUT', 'severity' => 'wajib', 'confidence' => .95,
            'observed' => 'Terpotong', 'expected' => 'Utuh', 'recommendation' => 'Perbaiki',
            'quote' => '', 'bbox' => null,
        ]]]), 1);
    }

    public function test_issn_colon_is_only_required_in_catalogue(): void
    {
        $evaluator = app(RuleEvaluator::class);
        $catalogue = $this->pageResult(4, ['page_type' => 'catalogue', 'text' => 'ISSN 0852-0865']);
        $cover = $this->pageResult(1, ['page_type' => 'front_cover', 'text' => 'ISSN: 0852-0865']);
        $this->assertContains('ISSN.COLON', array_column($evaluator->evaluate($catalogue), 'rule_code'));
        $this->assertContains('ISSN.COLON', array_column($evaluator->evaluate($cover), 'rule_code'));
        $this->assertNotContains('ISSN.COLON', array_column($evaluator->evaluate($this->pageResult(4, ['page_type' => 'catalogue', 'text' => 'ISSN: 0852-0865'])), 'rule_code'));
    }

    public function test_identity_mismatch_keeps_both_page_evidences(): void
    {
        $pages = [
            $this->pageResult(1, ['identity' => ['volume' => '31']]),
            $this->pageResult(4, ['identity' => ['volume' => '17']]),
        ];
        $findings = app(CrossChecker::class)->run($pages);
        $match = collect($findings)->firstWhere('rule_code', 'IDENTITY.CONSISTENCY');
        $this->assertSame([1, 4], array_column($match['evidences'], 'page'));
    }
}
