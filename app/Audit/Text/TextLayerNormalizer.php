<?php

declare(strict_types=1);

namespace App\Audit\Text;

class TextLayerNormalizer
{
    /**
     * Common broken word splits caused by PDF kerning / column layout extraction.
     *
     * @var array<string, string>
     */
    protected array $knownSplits = [
        'hor ticulture' => 'horticulture',
        'transpor tasi' => 'transportasi',
        'transpor tation' => 'transportation',
        'jakar ta' => 'jakarta',
        'per tanian' => 'pertanian',
        'kecama tan' => 'kecamatan',
        'kabupa ten' => 'kabupaten',
        'peme rintahan' => 'pemerintahan',
        'memberik an' => 'memberikan',
        'merupak an' => 'merupakan',
        'ditingk atkan' => 'ditingkatkan',
        'deng an' => 'dengan',
        'pad a' => 'pada',
        'seb agai' => 'sebagai',
        'unt uk' => 'untuk',
    ];

    /**
     * Normalize the text layer by fixing line breaks, hyphenation, and kerning splits.
     *
     * @return array{normalized_text: string, joins: array<int, array{from: string, to: string}>}
     */
    public function normalize(string $rawText): array
    {
        $joins = [];
        $text = str_replace(["\r\n", "\r"], "\n", $rawText);

        // 1. Line-end hyphenation repair: e.g. "pertani-\nan" -> "pertanian"
        $text = preg_replace_callback('/([a-zA-Z\x{00C0}-\x{024F}]{2,})[-‐‑‒–—]\s*\n\s*([a-zA-Z\x{00C0}-\x{024F}]{2,})/u', function ($matches) use (&$joins) {
            $from = $matches[0];
            $to = $matches[1].$matches[2];
            $joins[] = ['from' => $from, 'to' => $to];

            return $to;
        }, $text);

        // 2. Known kerning / extraction splits
        foreach ($this->knownSplits as $splitPattern => $replacement) {
            $pattern = '/\b'.preg_replace('/\s+/', '\s+', preg_quote($splitPattern, '/')).'\b/iu';
            if (preg_match($pattern, $text)) {
                $text = (string) preg_replace_callback($pattern, function ($matches) use ($replacement, &$joins) {
                    $original = $matches[0];
                    $cased = $this->matchCasing($original, $replacement);
                    $joins[] = ['from' => $original, 'to' => $cased];

                    return $cased;
                }, $text);
            }
        }

        return [
            'normalized_text' => $text,
            'joins' => $joins,
        ];
    }

    private function matchCasing(string $original, string $target): string
    {
        $clean = str_replace(' ', '', $original);
        if ($clean !== '' && ctype_upper($clean)) {
            return mb_strtoupper($target, 'UTF-8');
        }
        if ($original !== '' && ctype_upper(mb_substr($original, 0, 1))) {
            return ucfirst($target);
        }

        return mb_strtolower($target, 'UTF-8');
    }
}
