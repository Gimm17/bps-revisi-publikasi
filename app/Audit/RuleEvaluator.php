<?php

declare(strict_types=1);

namespace App\Audit;

class RuleEvaluator
{
    public function evaluate(array $page): array
    {
        $findings = [];
        preg_match_all('/ISSN\s*(:?)\s*(\d{4}-[\dX]{4})/i', $page['text'], $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if (($page['page_type'] === 'catalogue') !== ($match[1] === ':')) {
                $expected = ($page['page_type'] === 'catalogue' ? 'ISSN: ' : 'ISSN ').$match[2];
                $findings[] = $this->finding('ISSN.COLON', $page, $match[0], $expected, 'Ubah menjadi '.$expected.'.');
            }
            $digits = str_replace('-', '', strtoupper($match[2]));
            $sum = 0;
            for ($i = 0; $i < 8; $i++) {
                $sum += ($digits[$i] === 'X' ? 10 : (int) $digits[$i]) * (8 - $i);
            }
            if ($sum % 11 !== 0) {
                $findings[] = $this->finding('ISSN.CHECKSUM', $page, $match[2], 'ISSN dengan digit kontrol yang valid', 'Verifikasi seluruh nomor ISSN terhadap metadata resmi; jangan membuat nomor pengganti.', 'verifikasi');
            }
        }

        return $findings;
    }

    public function finding(string $rule, array $page, string $observed, string $expected, string $recommendation, string $severity = 'wajib'): array
    {
        return ['rule_code' => $rule, 'severity' => $severity, 'observed' => $observed, 'expected' => $expected, 'recommendation' => $recommendation, 'confidence' => .99, 'source' => 'deterministic', 'evidences' => [['page' => $page['physical_page'], 'quote' => $observed, 'bbox' => null]]];
    }
}
