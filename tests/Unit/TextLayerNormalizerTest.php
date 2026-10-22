<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Audit\Text\TextLayerNormalizer;
use PHPUnit\Framework\TestCase;

class TextLayerNormalizerTest extends TestCase
{
    public function test_normalizes_line_end_hyphenation(): void
    {
        $normalizer = new TextLayerNormalizer;
        $input = "Sektor pertani-\nan dan pemerin-\ntahan merupakan sektor penting.";
        $res = $normalizer->normalize($input);

        $this->assertStringContainsString('pertanian', $res['normalized_text']);
        $this->assertStringContainsString('pemerintahan', $res['normalized_text']);
        $this->assertNotEmpty($res['joins']);
    }

    public function test_fixes_known_kerning_splits_with_case_preservation(): void
    {
        $normalizer = new TextLayerNormalizer;
        $input = "Produksi Hor ticulture dan Transpor tasi di wilayah Jakar ta memberik an kontribusi.";
        $res = $normalizer->normalize($input);

        $this->assertStringContainsString('Horticulture', $res['normalized_text']);
        $this->assertStringContainsString('Transportasi', $res['normalized_text']);
        $this->assertStringContainsString('Jakarta', $res['normalized_text']);
        $this->assertStringContainsString('memberikan', $res['normalized_text']);

        // Check uppercase preservation
        $upperInput = "SEKTOR TRANSPOR TASI DAN PER TANIAN";
        $upperRes = $normalizer->normalize($upperInput);
        $this->assertStringContainsString('TRANSPORTASI', $upperRes['normalized_text']);
        $this->assertStringContainsString('PERTANIAN', $upperRes['normalized_text']);
    }
}
