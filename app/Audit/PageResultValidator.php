<?php

declare(strict_types=1);

namespace App\Audit;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PageResultValidator
{
    public function validate(array $data, int $page): array
    {
        // Pre-sanitize table bboxes, titles, and structure (self-healing for malformed/orphan draft tables)
        if (isset($data['inventory']['tables']) && is_array($data['inventory']['tables'])) {
            foreach ($data['inventory']['tables'] as $k => $table) {
                if (is_array($table)) {
                    if (array_key_exists('bbox', $table)) {
                        $data['inventory']['tables'][$k]['bbox'] = $this->sanitizeBox($table['bbox']);
                    }
                    if (empty(trim((string) ($table['title'] ?? '')))) {
                        $data['inventory']['tables'][$k]['title'] = '[Tanpa Judul]';
                    }
                    if (! isset($table['headers']) || ! is_array($table['headers'])) {
                        $data['inventory']['tables'][$k]['headers'] = [];
                    }
                    if (! isset($table['rows']) || ! is_array($table['rows'])) {
                        $data['inventory']['tables'][$k]['rows'] = [];
                    }
                    if (! isset($table['continued'])) {
                        $data['inventory']['tables'][$k]['continued'] = false;
                    }
                }
            }
        }

        // Pre-sanitize finding bboxes (self-healing for AI coordinate variances)
        if (isset($data['findings']) && is_array($data['findings'])) {
            foreach ($data['findings'] as $k => $finding) {
                if (is_array($finding) && array_key_exists('bbox', $finding)) {
                    $sanitized = $this->sanitizeBox($finding['bbox']);
                    $quote = trim((string) ($finding['quote'] ?? ''));
                    if ($sanitized === null && $quote !== '') {
                        $data['findings'][$k]['bbox'] = null;
                    } else {
                        $data['findings'][$k]['bbox'] = $sanitized;
                    }
                }
            }
        }

        // Pre-sanitize page_type
        if (isset($data['page_type'])) {
            $data['page_type'] = $this->sanitizePageType($data['page_type']);
        }

        Validator::make($data, [
            'physical_page' => ['required', 'integer', Rule::in([$page])],
            'page_type' => ['required', Rule::in(['front_cover', 'title', 'catalogue', 'team', 'preface', 'contents', 'table_list', 'figure_list', 'chapter', 'table', 'narrative', 'bibliography', 'blank', 'back_cover'])],
            'readability' => ['required', Rule::in(['readable', 'uncertain', 'unreadable'])],
            'printed_page' => ['nullable', 'string', 'max:30'], 'text' => ['present', 'string', 'max:200000'],
            'inventory' => ['required', 'array'], 'inventory.headings' => ['present', 'array'],
            'inventory.tables' => ['present', 'array', 'max:50'], 'inventory.figures' => ['present', 'array'],
            'inventory.tables.*' => ['required', 'array'],
            'inventory.tables.*.number' => ['nullable', 'string', 'max:50'],
            'inventory.tables.*.title' => ['required', 'string', 'max:3000'],
            'inventory.tables.*.headers' => ['present', 'array'],
            'inventory.tables.*.rows' => ['present', 'array', 'max:2000'],
            'inventory.tables.*.rows.*' => ['array'],
            'inventory.tables.*.continued' => ['required', 'boolean'],
            'inventory.tables.*.component_rows' => ['sometimes', 'array'],
            'inventory.tables.*.component_rows.*' => ['array'],
            'inventory.tables.*.total' => ['sometimes', 'nullable', 'array'],
            'inventory.tables.*.bbox' => ['nullable', 'array', 'size:4'],
            'inventory.figures.*' => ['array'],
            'inventory.header' => ['present', 'string'], 'inventory.footer' => ['present', 'string'],
            'inventory.chapter_title' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'inventory.running_title_id' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'inventory.issn_barcode_present' => ['sometimes', 'nullable', 'boolean'],
            'identity' => ['present', 'array'], 'contents' => ['present', 'array'],
            'contents.*' => ['array'], 'contents.*.title' => ['required', 'string', 'max:3000'],
            'contents.*.type' => ['sometimes', 'string', 'max:50'],
            'contents.*.target_page' => ['present', 'nullable', 'string', 'max:30'],
            'findings' => ['present', 'array', 'max:100'],
            'findings.*.rule_code' => ['required', Rule::in(array_keys(app(RuleRegistry::class)->all()))],
            'findings.*.severity' => ['required', Rule::in(['wajib', 'saran', 'verifikasi'])],
            'findings.*.confidence' => ['required', 'numeric', 'between:0,1'],
            'findings.*.observed' => ['required', 'string', 'max:5000'],
            'findings.*.expected' => ['required', 'string', 'max:5000'],
            'findings.*.recommendation' => ['required', 'string', 'max:5000'],
            'findings.*.quote' => ['nullable', 'string', 'max:3000'], 'findings.*.bbox' => ['nullable', 'array', 'size:4'],
        ])->validate();
        if ($data['readability'] === 'readable' && $data['page_type'] !== 'blank' && trim($data['text']) === '') {
            $this->reject('Halaman berisi konten tidak boleh dinyatakan terbaca tanpa transkripsi.');
        }
        foreach ($data['contents'] as $index => $entry) {
            if ($data['readability'] === 'readable' && ! in_array($entry['type'] ?? '', ['heading', 'section_header', 'table_split', 'table_continued', 'split_entry'], true) && trim((string) ($entry['target_page'] ?? '')) === '') {
                throw ValidationException::withMessages(['contents.'.$index.'.target_page' => 'Nomor halaman tujuan entri daftar belum terbaca. Gunakan uncertain, jangan menebak nomor halaman.']);
            }
        }
        foreach ($data['identity'] as $value) {
            if (! is_scalar($value) && $value !== null) {
                $this->reject('Identitas harus berupa nilai teks tunggal.');
            }
        }
        foreach ($data['inventory']['tables'] as $table) {
            foreach ($table['rows'] as $row) {
                foreach ($row as $value) {
                    if (! is_scalar($value) && $value !== null) {
                        $this->reject('Sel tabel tidak valid.');
                    }
                }
            }
            if (isset($table['bbox'])) {
                $this->checkBox($table['bbox']);
            }
        }
        foreach ($data['findings'] as $finding) {
            $quote = trim($finding['quote'] ?? '');
            $bbox = $finding['bbox'] ?? null;
            if ($quote === '' && ! $bbox) {
                $this->reject('Temuan tidak mempunyai bukti.');
            }
            if ($bbox) {
                $this->checkBox($bbox);
            } elseif (! str_contains($this->normalize($data['text']), $this->normalize($quote))) {
                $normData = $this->normalize($data['text']);
                $normQuote = $this->normalize($quote);
                $cleanData = preg_replace('/[^\p{L}\p{N}]/u', '', $normData);
                $cleanQuote = preg_replace('/[^\p{L}\p{N}]/u', '', $normQuote);
                if ($cleanQuote === '' || ! str_contains($cleanData, $cleanQuote)) {
                    $this->reject('Kutipan tidak ditemukan pada teks halaman.');
                }
            }
        }

        return $data;
    }

    private function normalize(string $text): string
    {
        return preg_replace('/\s+/u', ' ', trim($text));
    }

    private function checkBox(array $bbox): void
    {
        $sanitized = $this->sanitizeBox($bbox);
        if ($sanitized === null) {
            $this->reject('Format koordinat bukti tidak valid.');
        }
    }

    private function sanitizeBox(mixed $bbox): ?array
    {
        if (! is_array($bbox)) {
            return null;
        }
        $vals = array_values($bbox);
        if (count($vals) !== 4) {
            return null;
        }
        foreach ($vals as $k => $v) {
            if (! is_numeric($v)) {
                return null;
            }
            $vals[$k] = (float) $v;
        }

        // Handle 0..1000 integer coordinates (standard Gemini API bbox format)
        $maxVal = max($vals);
        if ($maxVal > 1.05 && $maxVal <= 1000.0) {
            $vals = array_map(fn ($v) => $v / 1000.0, $vals);
        } elseif ($maxVal > 1000.0) {
            return null;
        }

        $y0 = max(0.0, min(1.0, (float) $vals[0]));
        $x0 = max(0.0, min(1.0, (float) $vals[1]));
        $y1 = max(0.0, min(1.0, (float) $vals[2]));
        $x1 = max(0.0, min(1.0, (float) $vals[3]));

        if ($y0 > $y1) {
            [$y0, $y1] = [$y1, $y0];
        }
        if ($x0 > $x1) {
            [$x0, $x1] = [$x1, $x0];
        }

        if (abs($y1 - $y0) < 0.001 || abs($x1 - $x0) < 0.001) {
            return null;
        }

        return [round($y0, 4), round($x0, 4), round($y1, 4), round($x1, 4)];
    }

    private function sanitizePageType(mixed $raw): string
    {
        $valid = [
            'front_cover', 'title', 'catalogue', 'team', 'preface',
            'contents', 'table_list', 'figure_list', 'chapter',
            'table', 'narrative', 'bibliography', 'blank', 'back_cover',
        ];

        if (! is_string($raw) || trim($raw) === '') {
            return 'narrative';
        }

        $type = strtolower(trim($raw));
        $type = str_replace(['-', ' '], '_', $type);

        if (in_array($type, $valid, true)) {
            return $type;
        }

        $aliases = [
            'cover' => 'front_cover',
            'front' => 'front_cover',
            'cover_front' => 'front_cover',
            'back' => 'back_cover',
            'back_cover_page' => 'back_cover',
            'title_page' => 'title',
            'catalog' => 'catalogue',
            'katalog' => 'catalogue',
            'editorial' => 'team',
            'editorial_team' => 'team',
            'contributors' => 'team',
            'foreword' => 'preface',
            'kata_pengantar' => 'preface',
            'toc' => 'contents',
            'table_of_contents' => 'contents',
            'daftar_isi' => 'contents',
            'tables' => 'table',
            'tabel' => 'table',
            'tables_list' => 'table_list',
            'daftar_tabel' => 'table_list',
            'figures' => 'figure_list',
            'figure' => 'figure_list',
            'daftar_gambar' => 'figure_list',
            'charts' => 'figure_list',
            'bab' => 'chapter',
            'chapter_cover' => 'chapter',
            'text' => 'narrative',
            'narasi' => 'narrative',
            'body' => 'narrative',
            'content' => 'narrative',
            'references' => 'bibliography',
            'daftar_pustaka' => 'bibliography',
            'empty' => 'blank',
            'white' => 'blank',
        ];

        return $aliases[$type] ?? 'narrative';
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['ai' => $message]);
    }
}
