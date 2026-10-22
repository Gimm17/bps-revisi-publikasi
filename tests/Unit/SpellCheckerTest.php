<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Audit\Text\SpellChecker;
use Tests\TestCase;

class SpellCheckerTest extends TestCase
{
    public function test_catches_known_literal_typos_and_suggests_corrections(): void
    {
        $checker = new SpellChecker;

        $text = 'Lembaga ini tekait dengan distrcit dan industri pharmautical di sektor parawisata serta accomodation.';
        $typos = $checker->findTypos($text);

        $words = array_column($typos, 'word');
        $suggestions = array_column($typos, 'suggestion');

        $this->assertContains('tekait', $words);
        $this->assertContains('terkait', $suggestions);

        $this->assertContains('distrcit', $words);
        $this->assertContains('district', $suggestions);

        $this->assertContains('pharmautical', $words);
        $this->assertContains('pharmaceutical', $suggestions);

        $this->assertContains('parawisata', $words);
        $this->assertContains('pariwisata', $suggestions);

        $this->assertContains('accomodation', $words);
        $this->assertContains('accommodation', $suggestions);
    }

    public function test_catches_conjoined_words_and_missing_hyphen_reduplications(): void
    {
        $checker = new SpellChecker;

        $text = 'Tujuan utamamendidik dirimelalui bagianbagian buahbuahan yang segar.';
        $typos = $checker->findTypos($text);

        $suggestions = array_column($typos, 'suggestion');

        $this->assertContains('utama mendidik', $suggestions);
        $this->assertContains('diri melalui', $suggestions);
        $this->assertContains('bagian-bagian', $suggestions);
        $this->assertContains('buah-buahan', $suggestions);
    }

    public function test_does_not_flag_bps_terms_or_regional_names(): void
    {
        $checker = new SpellChecker;

        $text = 'Data BPS KCDA SLS Podes 2024 dan nama desa Sansarino Malotong Labuan Bailo Bonerato Dondo Layouters.';
        $typos = $checker->findTypos($text);

        $this->assertEmpty($typos, 'BPS terms and regional names should not be flagged as typos');
    }

    public function test_safe_threshold_caps_typo_flooding(): void
    {
        $checker = new SpellChecker;

        // Generate text with 20 fake typos
        $garbled = 'abcdef1 abcdef2 abcdef3 abcdef4 abcdef5 abcdef6 abcdef7 abcdef8 abcdef9 abcdef10 abcdef11 abcdef12 abcdef13 abcdef14 abcdef15 abcdef16 abcdef17 abcdef18 abcdef19 abcdef20';
        $finding = $checker->checkPage($garbled, 10);

        $this->assertNull($finding, 'Should return null when page has more than 15 typos to prevent OCR noise');
    }
}
