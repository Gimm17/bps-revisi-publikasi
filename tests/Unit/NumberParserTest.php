<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Audit\Numeric\NumberParser;
use PHPUnit\Framework\TestCase;

class NumberParserTest extends TestCase
{
    public function test_parses_indonesian_and_standard_number_formats(): void
    {
        $parser = new NumberParser;

        $this->assertSame(1234.56, $parser->parse('1.234,56'));
        $this->assertSame(1754.0, $parser->parse('1.754'));
        $this->assertSame(56.29, $parser->parse('56,29'));
        $this->assertSame(100.0, $parser->parse('100,00'));
        $this->assertSame(0.5, $parser->parse('0,5'));
        $this->assertSame(1234.56, $parser->parse('1,234.56'));
    }

    public function test_handles_bps_symbols_and_superscripts(): void
    {
        $parser = new NumberParser;

        // Zero / nihil
        $this->assertSame(0.0, $parser->parse('-'));
        $this->assertSame(0.0, $parser->parse('–'));
        $this->assertSame(0.0, $parser->parse('—'));
        $this->assertSame(0.0, $parser->parse('-³'));

        // Footnotes / superscripts stripped
        $this->assertSame(59.0, $parser->parse('59¹'));
        $this->assertSame(180.0, $parser->parse('180¹²'));

        // Not available / confidential / non-numeric
        $this->assertNull($parser->parse('…'));
        $this->assertNull($parser->parse('...'));
        $this->assertNull($parser->parse('x'));
        $this->assertNull($parser->parse('X'));
        $this->assertNull($parser->parse('Sansarino'));
        $this->assertNull($parser->parse(''));
        $this->assertNull($parser->parse(null));
    }
}
