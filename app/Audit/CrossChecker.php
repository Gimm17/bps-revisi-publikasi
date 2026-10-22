<?php

declare(strict_types=1);

namespace App\Audit;

class CrossChecker
{
    public function run(array $pages): array
    {
        $findings = [];
        $identity = [];
        $tables = [];
        $printed = [];
        foreach ($pages as $page) {
            foreach (['title', 'year', 'volume', 'catalogue', 'issn', 'publisher'] as $field) {
                $value = $page['identity'][$field] ?? null;
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $identity[$field][] = ['page' => $page['physical_page'], 'quote' => (string) $value, 'bbox' => null];
                }
            }
            if (! empty($page['printed_page'])) {
                $printed[(string) $page['printed_page']][] = $page;
            }
            foreach ($page['inventory']['tables'] ?? [] as $table) {
                if (! empty($table['number'])) {
                    $tables[(string) $table['number']][] = ['page' => $page['physical_page'], 'table' => $table];
                }
                if (! empty($table['component_rows']) && isset($table['total']) && is_array($table['total'])) {
                    foreach ($table['total'] as $column => $total) {
                        $values = array_column($table['component_rows'], $column);
                        if (count($values) !== count($table['component_rows']) || ! is_numeric($total) || count(array_filter($values, 'is_numeric')) !== count($values)) {
                            continue;
                        }
                        $sum = array_sum($values);
                        if (abs((float) $total - $sum) > .01) {
                            $findings[] = $this->make('TABLE.TOTAL', 'Total tercetak '.$total.'; hasil jumlah komponen '.$sum.'.', 'Total dan komponen konsisten untuk unit/kolom yang sama.', 'Verifikasi nilai total dan semua komponen pada sumber data. Selisih bukan dasar untuk mengganti angka secara otomatis.', [['page' => $page['physical_page'], 'quote' => ($table['title'] ?? $table['number']).': '.$total.' dibanding '.$sum, 'bbox' => $table['bbox'] ?? null]], 'verifikasi');
                        }
                    }
                }
            }
        }
        foreach ($identity as $field => $evidence) {
            if (count(array_unique(array_map(fn ($e) => $this->normalize($e['quote']), $evidence))) > 1) {
                $findings[] = $this->make('IDENTITY.CONSISTENCY', 'Identitas '.$field.' berbeda: '.implode('; ', array_map(fn ($e) => 'PDF hlm. '.$e['page'].' “'.$e['quote'].'”', $evidence)), 'Identitas publikasi sama pada semua bagian yang merujuk publikasi ini.', 'Cocokkan '.$field.' dengan metadata resmi kemudian samakan bagian yang terbukti salah.', $evidence, 'verifikasi');
            }
        }
        $publicationTitle = $identity['title'][0]['quote'] ?? '';
        foreach ($pages as $page) {
            $number = $page['printed_page'] ?? '';
            if (ctype_digit((string) $number) && ! in_array($page['page_type'], ['blank', 'chapter', 'front_cover', 'back_cover'])) {
                $running = $page['inventory']['running_title_id'] ?? '';
                $expected = (int) $number % 2 ? ($page['inventory']['chapter_title'] ?? '') : $publicationTitle;
                if ($running !== '' && $expected !== '' && $this->normalize($running) !== $this->normalize($expected)) {
                    $findings[] = $this->make('RUNNING.TITLE', 'Running title “'.$running.'” pada halaman tercetak '.$number.'.', 'Running title “'.$expected.'”.', 'Halaman ganjil menggunakan judul bab dan halaman genap menggunakan judul publikasi. Sesuaikan setelah verifikasi pembacaan header/footer.', [['page' => $page['physical_page'], 'quote' => $running, 'bbox' => null]], 'verifikasi');
                }
            }
            foreach ($page['contents'] ?? [] as $entry) {
                if (! is_array($entry) || empty($entry['target_page']) || empty($entry['title'])) {
                    continue;
                }
                $targets = $printed[(string) $entry['target_page']] ?? [];
                if (! $targets) {
                    continue;
                }
                $haystack = implode(' ', array_column($targets, 'text'));

                $titleParts = explode('/', (string) $entry['title']);
                $idTitle = trim($titleParts[0]);
                $enTitle = isset($titleParts[1]) ? trim($titleParts[1]) : '';

                $normHaystack = $this->normalizeAdvanced($haystack);
                $normId = $this->normalizeAdvanced($idTitle);
                $normEn = $enTitle !== '' ? $this->normalizeAdvanced($enTitle) : '';

                $matches = false;
                if ($normId !== '' && str_contains($normHaystack, $normId)) {
                    $matches = true;
                } elseif ($normEn !== '' && str_contains($normHaystack, $normEn)) {
                    $matches = true;
                } else {
                    foreach ($targets as $tPage) {
                        foreach ($tPage['inventory']['tables'] ?? [] as $tbl) {
                            $tblTitle = $this->normalizeAdvanced($tbl['title'] ?? '');
                            similar_text($normId, $tblTitle, $pct);
                            if ($pct >= 80.0) {
                                $matches = true;
                                break 2;
                            }
                        }
                    }
                }

                if (! $matches) {
                    $findings[] = $this->make('CONTENTS.MATCH', 'Judul daftar “'.$entry['title'].'” belum cocok dengan isi halaman tercetak '.$entry['target_page'].'.', 'Judul dan halaman daftar identik dengan judul pada isi.', 'Periksa judul dan nomor halaman pada daftar serta isi; pastikan bukan artefak pembacaan AI.', [['page' => $page['physical_page'], 'quote' => $entry['title'], 'bbox' => null], ['page' => $targets[0]['physical_page'], 'quote' => mb_substr($targets[0]['text'], 0, 300), 'bbox' => null]], 'verifikasi');
                }
            }
        }
        $lastByPrefix = [];
        foreach ($tables as $number => $occurrences) {
            $originals = array_filter($occurrences, fn ($entry) => empty($entry['table']['continued']));
            if (count($originals) > 1) {
                $findings[] = $this->make('TABLE.NUMBER', 'Nomor tabel '.$number.' muncul lebih dari sekali sebagai tabel baru.', 'Nomor tabel unik; tabel lanjutan ditandai.', 'Verifikasi apakah tabel lanjutan atau nomor tabel ganda sebelum memperbaiki penomoran.', array_map(fn ($entry) => ['page' => $entry['page'], 'quote' => $number, 'bbox' => $entry['table']['bbox'] ?? null], array_values($originals)), 'verifikasi');
            }
            if (preg_match('/^(.+)\.(\d+)$/', (string) $number, $m)) {
                $last = $lastByPrefix[$m[1]] ?? null;
                if ($last && (int) $m[2] > $last['suffix'] + 1) {
                    $findings[] = $this->make('TABLE.NUMBER', 'Urutan tabel melompat dari '.$last['number'].' ke '.$number.'.', 'Urutan nomor tabel lengkap dalam kelompok yang sama.', 'Verifikasi tabel yang mungkin terlewat atau salah baca; koreksi hanya jika nomor yang hilang benar-benar tidak ada.', [['page' => $last['page'], 'quote' => $last['number'], 'bbox' => null], ['page' => $occurrences[0]['page'], 'quote' => (string) $number, 'bbox' => null]], 'verifikasi');
                }
                $lastByPrefix[$m[1]] = ['suffix' => (int) $m[2], 'number' => (string) $number, 'page' => $occurrences[0]['page']];
            }
        }
        $issn = $identity['issn'][0]['quote'] ?? null;
        foreach ($pages as $page) {
            if ($page['page_type'] === 'back_cover' && $issn && ($page['inventory']['issn_barcode_present'] ?? null) === false) {
                $findings[] = $this->make('BACK.BARCODE', 'Barcode ISSN tidak teridentifikasi pada kover belakang.', 'Publikasi ber-ISSN memuat barcode ISSN pada kover belakang.', 'Periksa gambar kover belakang; bila memang belum ada, tambahkan barcode untuk ISSN resmi '.$issn.'.', [['page' => $page['physical_page'], 'quote' => 'Kover belakang', 'bbox' => [0, 0, 1, 1]], $identity['issn'][0]], 'verifikasi');
            }
        }

        return $findings;
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(preg_replace('/[^\pL\pN]+/u', ' ', trim($value)));
    }

    private function normalizeAdvanced(string $value): string
    {
        $v = mb_strtolower(trim($value));
        $v = str_replace(['ibukota', 'ibu-kota'], 'ibu kota', $v);
        $v = preg_replace('/\b20\d\d\b/u', '', $v);

        return trim(preg_replace('/[^\pL\pN]+/u', ' ', (string) $v));
    }

    private function make(string $rule, string $observed, string $expected, string $recommendation, array $evidence, string $severity = 'wajib'): array
    {
        return ['rule_code' => $rule, 'observed' => $observed, 'expected' => $expected, 'recommendation' => $recommendation, 'severity' => $severity, 'confidence' => .85, 'source' => 'cross_check', 'evidences' => $evidence];
    }
}
