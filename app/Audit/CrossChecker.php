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
                $uniqueValues = [];
                $sampleQuotes = [];
                foreach ($evidence as $e) {
                    $norm = $this->normalize($e['quote']);
                    if (!isset($uniqueValues[$norm])) {
                        $uniqueValues[$norm] = true;
                        $sampleQuotes[] = 'PDF hlm. '.$e['page'].' “'.$e['quote'].'”';
                    }
                }
                $diffCount = count($evidence);
                $observedStr = $diffCount > 5
                    ? 'Identitas '.$field.' memiliki variasi nilai berbeda di '.$diffCount.' halaman, contoh: '.implode('; ', array_slice($sampleQuotes, 0, 4)).' (dan halaman lainnya).'
                    : 'Identitas '.$field.' berbeda: '.implode('; ', array_map(fn ($e) => 'PDF hlm. '.$e['page'].' “'.$e['quote'].'”', $evidence));

                $findings[] = $this->make('IDENTITY.CONSISTENCY', $observedStr, 'Identitas publikasi sama pada semua bagian yang merujuk publikasi ini.', 'Cocokkan '.$field.' dengan metadata resmi kemudian samakan bagian yang terbukti salah.', $evidence, 'verifikasi');
            }
        }

        // IDENTITY.WILAYAH_MISMATCH (Deteksi kelalaian kasus ganti kover)
        $coverText = '';
        foreach ($pages as $p) {
            if (in_array($p['page_type'] ?? '', ['front_cover', 'cover'], true)) {
                $coverText = (string) ($p['text'] ?? '');
                break;
            }
        }
        if ($coverText !== '' && preg_match('/\bKecamatan\s+([A-Za-z\s]+?)(?:\s+Dalam\s+Angka|\s+in\s+Figures|\n|$)/iu', $coverText, $covM)) {
            $coverDistrict = trim($covM[1]);
            foreach ($pages as $p) {
                $pType = $p['page_type'] ?? '';
                if (in_array($pType, ['title', 'catalogue', 'preface'], true)) {
                    $pText = (string) ($p['text'] ?? '');
                    if (preg_match_all('/\bKecamatan\s+([A-Za-z\s]+?)(?:\s+Dalam\s+Angka|\s+in\s+Figures|\n|\.)/iu', $pText, $distMatches, PREG_SET_ORDER)) {
                        foreach ($distMatches as $dm) {
                            $otherDistrict = trim($dm[1]);
                            if ($otherDistrict !== '' && mb_strtolower($otherDistrict) !== mb_strtolower($coverDistrict) && ! str_contains(mb_strtolower($coverDistrict), mb_strtolower($otherDistrict)) && ! str_contains(mb_strtolower($otherDistrict), mb_strtolower($coverDistrict))) {
                                $findings[] = $this->make(
                                    'IDENTITY.WILAYAH_MISMATCH',
                                    'Kover depan memuat "Kecamatan '.$coverDistrict.'", tetapi halaman '.$pType.' memuat "Kecamatan '.$otherDistrict.'".',
                                    'Nama kecamatan selaras di seluruh seksi publikasi.',
                                    'Terdeteksi ketidaksesuaian identitas wilayah (kasus ganti kover / sisa file kecamatan lain). Selaraskan seluruh penyebutan nama wilayah dengan "Kecamatan '.$coverDistrict.'".',
                                    [['page' => $p['physical_page'] ?? $p['page_number'] ?? $p['page'] ?? 1, 'quote' => $dm[0], 'bbox' => null]],
                                    'wajib'
                                );
                                break 2;
                            }
                        }
                    }
                }
            }
        }

        // PREFACE.TITIMANGSA_YEAR (Sinkronisasi tahun titimangsa Kata Pengantar)
        $pubYear = $identity['year'][0]['quote'] ?? null;
        if (! $pubYear || ! ctype_digit((string) $pubYear)) {
            foreach ($pages as $p) {
                if (in_array($p['page_type'] ?? '', ['front_cover', 'cover', 'title'], true)) {
                    if (preg_match('/\b(202\d|201\d)\b/', (string) ($p['text'] ?? ''), $ym)) {
                        $pubYear = $ym[1];
                        break;
                    }
                }
            }
        }
        if ($pubYear && ctype_digit((string) $pubYear)) {
            foreach ($pages as $p) {
                if (($p['page_type'] ?? '') === 'preface') {
                    $pText = (string) ($p['text'] ?? '');
                    if (preg_match('/\b(?:Januari|Februari|Maret|April|Mei|Juni|Juli|Agustus|September|Oktober|November|Desember)\s+(20\d\d)\b/iu', $pText, $titimangsaM)) {
                        $titimangsaYear = $titimangsaM[1];
                        if ((int) $titimangsaYear < (int) $pubYear) {
                            $findings[] = $this->make(
                                'PREFACE.TITIMANGSA_YEAR',
                                'Titimangsa penandatanganan Kata Pengantar mencantumkan tahun '.$titimangsaYear.', padahal publikasi adalah edisi '.$pubYear.'.',
                                'Tahun titimangsa Kata Pengantar selaras dengan tahun publikasi ('.$pubYear.').',
                                'Perbarui angka tahun pada baris titimangsa penandatanganan Kata Pengantar menjadi tahun '.$pubYear.'.',
                                [['page' => $p['physical_page'] ?? $p['page_number'] ?? $p['page'] ?? 1, 'quote' => $titimangsaM[0], 'bbox' => null]],
                                'wajib'
                            );
                        }
                    }
                }
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

        // CHAPTER.START (Setiap awal bab wajib di halaman ganjil)
        foreach ($pages as $page) {
            $isChapter = ($page['page_type'] ?? '') === 'chapter' ||
                         preg_match('/^\s*(?:BAB|CHAPTER)\s+[1-9IVX]+\b/im', (string) ($page['text'] ?? ''));
            if ($isChapter) {
                $num = $page['printed_page'] ?? null;
                $pNum = is_numeric($num) ? (int) $num : ($page['physical_page'] ?? null);
                if ($pNum !== null && is_numeric($pNum) && ((int) $pNum) % 2 === 0) {
                    $findings[] = $this->make(
                        'CHAPTER.START',
                        'Awal Bab dimulai pada halaman genap (hlm. '.$pNum.').',
                        'Setiap awal bab baru atau pembatas bab wajib berada pada halaman ganjil (halaman kanan buku).',
                        'Sisipkan satu halaman putih penutup (blank page) sebelum awal bab ini agar posisinya bergeser ke halaman ganjil.',
                        [['page' => $page['physical_page'] ?? (int) $pNum, 'quote' => 'Awal Bab pada hlm. '.$pNum, 'bbox' => null]],
                        'wajib'
                    );
                }
            }
        }

        // PAGINATION.ORDER (Urutan penomoran halaman tidak boleh melompat atau mundur)
        $arabicPages = [];
        foreach ($pages as $page) {
            $printed = trim((string) ($page['printed_page'] ?? ''));
            if (ctype_digit($printed)) {
                $arabicPages[] = ['val' => (int) $printed, 'page' => $page['physical_page'] ?? (int) $printed];
            }
        }
        for ($i = 0; $i < count($arabicPages) - 1; $i++) {
            $curr = $arabicPages[$i];
            $next = $arabicPages[$i + 1];
            if ($next['val'] > $curr['val'] + 1) {
                $findings[] = $this->make(
                    'PAGINATION.ORDER',
                    'Nomor halaman tercetak melompat dari hlm. '.$curr['val'].' ke hlm. '.$next['val'].'.',
                    'Urutan penomoran halaman wajib berurutan tanpa lompatan angka.',
                    'Periksa kelengkapan halaman antara hlm. '.$curr['val'].' dan hlm. '.$next['val'].'; pastikan tidak ada nomor atau lembar yang terlewat.',
                    [['page' => $curr['page'], 'quote' => 'Hlm. '.$curr['val'], 'bbox' => null], ['page' => $next['page'], 'quote' => 'Hlm. '.$next['val'], 'bbox' => null]],
                    'wajib'
                );
            } elseif ($next['val'] < $curr['val']) {
                $findings[] = $this->make(
                    'PAGINATION.ORDER',
                    'Nomor halaman tercetak mundur dari hlm. '.$curr['val'].' ke hlm. '.$next['val'].'.',
                    'Urutan penomoran halaman wajib berurutan maju.',
                    'Periksa susunan urutan halaman agar tidak terbalik.',
                    [['page' => $curr['page'], 'quote' => 'Hlm. '.$curr['val'], 'bbox' => null], ['page' => $next['page'], 'quote' => 'Hlm. '.$next['val'], 'bbox' => null]],
                    'wajib'
                );
            }
        }

        // CATALOGUE.PAGE_COUNT (Sinkronisasi angka total halaman KDT dengan halaman isi terakhir)
        $catPage = null;
        foreach ($pages as $p) {
            if (($p['page_type'] ?? '') === 'catalogue') {
                $catPage = $p;
                break;
            }
        }
        if ($catPage) {
            $catText = (string) ($catPage['text'] ?? '');
            if (preg_match('/\b([ivxldcm]+)\s*\+\s*(\d+)\s*(?:hal|hlm|pages|halaman)\b/iu', $catText, $catCountM)) {
                $catArabicNum = (int) $catCountM[2];
                $maxArabic = 0;
                foreach ($pages as $p) {
                    $num = trim((string) ($p['printed_page'] ?? ''));
                    if (ctype_digit($num) && (int) $num > $maxArabic) {
                        $maxArabic = (int) $num;
                    }
                }
                if ($maxArabic > 0 && abs($catArabicNum - $maxArabic) > 0) {
                    $findings[] = $this->make(
                        'CATALOGUE.PAGE_COUNT',
                        'Teks katalog (KDT) mencantumkan total '.$catArabicNum.' halaman, tetapi konten terakhir buku bernomor '.$maxArabic.'.',
                        'Jumlah halaman pada teks katalog KDT sinkron dengan halaman konten terakhir buku ('.$maxArabic.' hal.).',
                        'Sesuaikan angka halaman pada teks Katalog Dalam Terbitan menjadi "... + '.$maxArabic.' hal." agar akurat dengan isi buku.',
                        [['page' => $catPage['physical_page'] ?? 1, 'quote' => $catCountM[0], 'bbox' => null]],
                        'wajib'
                    );
                }
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
