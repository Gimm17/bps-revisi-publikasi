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
        foreach ($data['contents'] as $entry) {
            if ($data['readability'] === 'readable' && ($entry['type'] ?? '') !== 'heading' && trim($entry['target_page'] ?? '') === '') {
                $this->reject('Nomor halaman tujuan entri daftar belum terbaca. Gunakan uncertain, jangan menebak nomor halaman.');
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
                $this->reject('Kutipan tidak ditemukan pada teks halaman.');
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
        if (count($bbox) !== 4 || array_keys($bbox) !== [0, 1, 2, 3]) {
            $this->reject('Format koordinat bukti tidak valid.');
        }
        foreach ($bbox as $value) {
            if (! is_numeric($value) || $value < 0 || $value > 1) {
                $this->reject('Koordinat bukti berada di luar halaman.');
            }
        }
        if ($bbox[0] >= $bbox[2] || $bbox[1] >= $bbox[3]) {
            $this->reject('Koordinat bukti tidak valid.');
        }
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['ai' => $message]);
    }
}
