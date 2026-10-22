<?php

declare(strict_types=1);

namespace App\Audit\Numeric;

class NumberParser
{
    /**
     * Parse Indonesian/English formatted numbers and BPS symbols.
     *
     * Returns:
     * - float: parsed numerical value
     * - 0.0: for '-' or '–' (nihil)
     * - null: for '…', '...', 'x', non-numeric, or unavailable values
     */
    public function parse(?string $raw): ?float
    {
        if ($raw === null) {
            return null;
        }

        $clean = trim($raw);
        if ($clean === '') {
            return null;
        }

        // BPS symbols
        if ($clean === '-' || $clean === '–' || $clean === '—' || $clean === '0') {
            return 0.0;
        }

        // '…' or '...' = not available; 'x' = confidential / restricted
        if ($clean === '…' || $clean === '...' || mb_strtolower($clean) === 'x' || mb_strtolower($clean) === 'na' || mb_strtolower($clean) === 'n/a') {
            return null;
        }

        // Strip footnotes / superscripts at end of string: e.g. "59¹", "180¹²", "-³"
        $stripped = preg_replace('/[¹²³⁴⁵⁶⁷⁸⁹⁰]+$/u', '', $clean);
        $stripped = trim((string) $stripped);

        if ($stripped === '-' || $stripped === '–' || $stripped === '—') {
            return 0.0;
        }

        // Strip percent sign, non-breaking spaces
        $stripped = str_replace(['%', ' ', "\xc2\xa0"], '', $stripped);

        // Detect Indonesian format: 1.234,56 (dot thousands, comma decimal)
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $stripped)) {
            $normalized = str_replace('.', '', $stripped);
            $normalized = str_replace(',', '.', $normalized);

            return is_numeric($normalized) ? (float) $normalized : null;
        }

        // Detect English format: 1,234.56 (comma thousands, dot decimal)
        if (preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $stripped)) {
            $normalized = str_replace(',', '', $stripped);

            return is_numeric($normalized) ? (float) $normalized : null;
        }

        // Simple numbers with either comma or dot decimal: e.g. "56,29" or "1754" or "0.5"
        if (preg_match('/^-?\d+([.,]\d+)?$/', $stripped)) {
            $normalized = str_replace(',', '.', $stripped);

            return is_numeric($normalized) ? (float) $normalized : null;
        }

        return null;
    }
}
