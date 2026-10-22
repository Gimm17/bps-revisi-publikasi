<?php

declare(strict_types=1);

namespace App\Audit\Text;

class SpellChecker
{
    /**
     * @var array<string, bool>|null
     */
    protected static ?array $dictionary = null;

    /**
     * Direct known common typo corrections in BPS documents.
     *
     * @var array<string, string>
     */
    protected array $knownCorrections = [
        'tekait' => 'terkait',
        'distrcit' => 'district',
        'pharmautical' => 'pharmaceutical',
        'parawisata' => 'pariwisata',
        'renumeration' => 'remuneration',
        'complexs' => 'complex',
        'accomodation' => 'accommodation',
        'terdriri' => 'terdiri',
        'pariwsata' => 'pariwisata',
        'pendudk' => 'penduduk',
        'kecamtan' => 'kecamatan',
        'kabupten' => 'kabupaten',
        'pemeritah' => 'pemerintah',
    ];

    public function __construct()
    {
        $this->ensureDictionaryLoaded();
    }

    /**
     * Check spelling on normalized text for a single page.
     *
     * @param  array<string, mixed>  $extraContext  (e.g. table headers, entity names)
     * @return array<string, mixed>|null Returns structured finding or null if no typos or text corrupted (>15 typos)
     */
    public function checkPage(string $normalizedText, int $pageNumber, array $extraContext = []): ?array
    {
        $typos = $this->findTypos($normalizedText, $extraContext);

        if (empty($typos)) {
            return null;
        }

        // Safe threshold: if > 15 typos, text is likely corrupted / OCR garbage; suppress to avoid noise
        if (count($typos) > 15) {
            return null;
        }

        $observedList = [];
        $expectedList = [];
        foreach ($typos as $t) {
            $observedList[] = '"'.$t['word'].'"';
            $expectedList[] = '"'.$t['suggestion'].'"';
        }

        $observed = 'Ditemukan potensi salah ketik (typo) pada teks halaman: '.implode(', ', $observedList).'.';
        $expected = 'Penulisan baku yang disarankan: '.implode(', ', $expectedList).'.';
        $recommendation = 'Perbaiki penulisan kata tersebut sesuai ejaan baku pada Kamus Bahasa Indonesia / peristilahan resmi publikasi.';

        return [
            'rule_code' => 'LANGUAGE.EJAAN',
            'severity' => 'saran',
            'source' => 'spellcheck',
            'observed' => $observed,
            'expected' => $expected,
            'recommendation' => $recommendation,
            'evidences' => [
                [
                    'physical_page' => $pageNumber,
                    'quote' => implode(', ', array_slice($observedList, 0, 5)),
                ],
            ],
            'typos' => $typos,
        ];
    }

    /**
     * Find individual typo occurrences with suggestions.
     *
     * @param  array<string, mixed>  $extraContext
     * @return array<int, array{word: string, suggestion: string, reason: string}>
     */
    public function findTypos(string $text, array $extraContext = []): array
    {
        $this->ensureDictionaryLoaded();

        // Extra context words (e.g. table column names, regional names)
        $customAllowed = [];
        foreach ($extraContext['allowed_words'] ?? [] as $w) {
            $customAllowed[mb_strtolower(trim((string) $w))] = true;
        }

        $tokens = preg_split('/[^\p{L}\p{N}_\-]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $found = [];
        $seen = [];

        foreach ($tokens as $token) {
            $clean = trim($token, '-_');
            $lower = mb_strtolower($clean, 'UTF-8');

            // Skip conditions
            if (mb_strlen($clean) < 4 || is_numeric($clean) || isset($seen[$lower])) {
                continue;
            }

            // Skip all-caps abbreviations (BPS, KCDA, SLS, PDRB, etc.)
            if (ctype_upper($clean) && mb_strlen($clean) <= 6 && ! isset($this->knownCorrections[$lower])) {
                continue;
            }

            // If word is known dictionary word or extra context word, valid!
            if (isset(self::$dictionary[$lower]) || isset($customAllowed[$lower])) {
                continue;
            }

            $seen[$lower] = true;

            // 1. Direct known correction lookup
            if (isset($this->knownCorrections[$lower])) {
                $suggestion = $this->knownCorrections[$lower];
                $found[] = [
                    'word' => $clean,
                    'suggestion' => $suggestion,
                    'reason' => 'known_typo',
                ];

                continue;
            }

            // 2. Missing hyphen in reduplication: e.g. "bagianbagian" -> "bagian-bagian", "buahbuahan" -> "buah-buahan"
            $redupSuggestion = $this->checkReduplication($lower);
            if ($redupSuggestion !== null) {
                $found[] = [
                    'word' => $clean,
                    'suggestion' => $redupSuggestion,
                    'reason' => 'missing_hyphen',
                ];

                continue;
            }

            // 3. Conjoined words check: e.g. "utamamendidik" -> "utama mendidik", "dirimelalui" -> "diri melalui"
            $splitSuggestion = $this->checkConjoinedWords($lower);
            if ($splitSuggestion !== null) {
                $found[] = [
                    'word' => $clean,
                    'suggestion' => $splitSuggestion,
                    'reason' => 'conjoined_words',
                ];

                continue;
            }

            // 4. Damerau-Levenshtein distance <= 1 to known dictionary words
            $levSuggestion = $this->findNearestWord($lower);
            if ($levSuggestion !== null) {
                $found[] = [
                    'word' => $clean,
                    'suggestion' => $levSuggestion,
                    'reason' => 'levenshtein_distance_1',
                ];
            }
        }

        return $found;
    }

    /**
     * Check if a token is two joined words: e.g. utamamendidik -> utama mendidik.
     */
    protected function checkConjoinedWords(string $word): ?string
    {
        $len = mb_strlen($word);
        if ($len < 8) {
            return null;
        }

        for ($i = 4; $i <= $len - 4; $i++) {
            $w1 = mb_substr($word, 0, $i);
            $w2 = mb_substr($word, $i);

            if (isset(self::$dictionary[$w1]) && isset(self::$dictionary[$w2])) {
                return $w1.' '.$w2;
            }
        }

        return null;
    }

    /**
     * Check if token is a reduplicated word missing a hyphen: bagianbagian -> bagian-bagian.
     */
    protected function checkReduplication(string $word): ?string
    {
        $len = mb_strlen($word);
        if ($len % 2 === 0 && $len >= 8) {
            $half = $len / 2;
            $w1 = mb_substr($word, 0, $half);
            $w2 = mb_substr($word, $half);

            if ($w1 === $w2 && isset(self::$dictionary[$w1])) {
                return $w1.'-'.$w2;
            }
        }

        // Special case for fruit / irregular: buahbuahan -> buah-buahan
        if (str_ends_with($word, 'an')) {
            $base = mb_substr($word, 0, -2);
            $baseLen = mb_strlen($base);
            if ($baseLen % 2 === 0 && $baseLen >= 6) {
                $half = (int) ($baseLen / 2);
                $w1 = mb_substr($base, 0, $half);
                $w2 = mb_substr($base, $half);
                if ($w1 === $w2 && isset(self::$dictionary[$w1])) {
                    return $w1.'-'.$w2.'an';
                }
            }
        }

        return null;
    }

    /**
     * Find nearest dictionary word with Levenshtein distance 1.
     */
    protected function findNearestWord(string $word): ?string
    {
        $len = mb_strlen($word);
        if ($len < 4) {
            return null;
        }

        $bestWord = null;
        $bestDist = PHP_INT_MAX;

        foreach (array_keys(self::$dictionary ?? []) as $dictWord) {
            $dLen = mb_strlen((string) $dictWord);
            if (abs($dLen - $len) > 1) {
                continue;
            }

            // Quick first char check to avoid heavy levenshtein on entire dictionary
            if ($dictWord[0] !== $word[0] && $len > 4) {
                continue;
            }

            $dist = levenshtein($word, (string) $dictWord);
            if ($dist === 1) {
                return (string) $dictWord;
            }
        }

        return null;
    }

    /**
     * Load dictionaries into memory once.
     */
    protected function ensureDictionaryLoaded(): void
    {
        if (self::$dictionary !== null) {
            return;
        }

        self::$dictionary = [];
        $files = [
            resource_path('dictionaries/bps_terms.txt'),
            resource_path('dictionaries/id_ID.dic'),
            resource_path('dictionaries/en_US.dic'),
        ];

        foreach ($files as $file) {
            if (file_exists($file)) {
                $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                foreach ($lines as $line) {
                    $w = mb_strtolower(trim($line));
                    if ($w !== '' && ! str_starts_with($w, '#')) {
                        self::$dictionary[$w] = true;
                    }
                }
            }
        }
    }
}
