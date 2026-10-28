<?php

declare(strict_types=1);

namespace App\Audit;

class RuleEvaluator
{
    /**
     * @param array<string, mixed> $page
     * @return array<int, array<string, mixed>>
     */
    public function evaluate(array $page): array
    {
        $findings = [];
        $text = (string) ($page['text'] ?? '');
        $pageType = (string) ($page['page_type'] ?? '');

        // 1. ISSN.COLON & CATALOGUE.ISSN_COLON_RULE
        preg_match_all('/ISSN\s*(:?)\s*(\d{4}-[\dX]{4})/i', $text, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $hasColon = ($match[1] === ':');
            if ($pageType === 'catalogue' && ! $hasColon) {
                $expected = 'ISSN: '.$match[2];
                $findings[] = $this->finding('CATALOGUE.ISSN_COLON_RULE', $page, $match[0], $expected, 'Pada Halaman Katalog, penulisan ISSN wajib menggunakan tanda titik dua rapat: '.$expected.'.');
            } elseif ($pageType !== 'catalogue' && $hasColon) {
                $expected = 'ISSN '.$match[2];
                $findings[] = $this->finding('ISSN.COLON', $page, $match[0], $expected, 'Di luar halaman katalog, penulisan ISSN wajib tanpa tanda titik dua: '.$expected.'.');
            }

            // ISSN.CHECKSUM (Modulo 11 ISO 3297)
            $digits = str_replace('-', '', strtoupper($match[2]));
            if (strlen($digits) === 8) {
                $sum = 0;
                for ($i = 0; $i < 8; $i++) {
                    $sum += ($digits[$i] === 'X' ? 10 : (int) $digits[$i]) * (8 - $i);
                }
                if ($sum % 11 !== 0) {
                    $findings[] = $this->finding('ISSN.CHECKSUM', $page, $match[2], 'ISSN dengan digit kontrol yang valid', 'Verifikasi seluruh nomor ISSN terhadap metadata resmi; jangan membuat nomor pengganti.', 'verifikasi');
                }
            }
        }

        // 2. DOCUMENT.RAW_TEMPLATE (Deteksi template master BPS mentah)
        if (preg_match('/\b(1102001\.xxxx|Volume\s+XX\b|Volume\s+xx\b|KABUPATEN\/KOTA|REGENCY\/MUNICIPALITY|ISSN\s+XXXX\s*-\s*XXXX|COVER\s+DEPAN)\b/u', $text, $tmplMatch)) {
            $findings[] = $this->finding(
                'DOCUMENT.RAW_TEMPLATE',
                $page,
                $tmplMatch[0],
                'Identitas buku riil resmi BPS',
                'Terdeteksi teks placeholder draf template master BPS ("'.$tmplMatch[0].'"). Wajib disesuaikan dengan data dan identitas riil daerah.',
                'wajib'
            );
        }

        // 3. TYPOGRAPHY.SUPERSCRIPT_UNIT (Satuan luas km² dan m²)
        if (preg_match('/(\((?:km2|m2)[\)\/]|\b(?:km2|m2)\b)/iu', $text, $unitMatch)) {
            $correctUnit = str_replace(['km2', 'm2', 'KM2', 'M2'], ['km²', 'm²', 'km²', 'm²'], $unitMatch[0]);
            $findings[] = $this->finding(
                'TYPOGRAPHY.SUPERSCRIPT_UNIT',
                $page,
                $unitMatch[0],
                $correctUnit,
                'Sesuai kaidah baku tipografi BPS, penulisan satuan luas wajib menggunakan simbol superskrip kuadrat ("'.$correctUnit.'"), bukan angka 2 biasa.',
                'wajib'
            );
        }

        // 4. CATALOGUE.NO_INDIVIDUAL_NAMES (Halaman Katalog bebas nama orang)
        if ($pageType === 'catalogue') {
            if (preg_match('/(?:Penyusun Naskah|Penyunting|Pembuat Kover|Cover Designer|Editor)\s*[:\/]\s*([^\n\r]+)/iu', $text, $catMatch)) {
                $lineContent = $catMatch[1];
                if (preg_match('/\b(S\.Si|S\.Tr\.Stat|S\.Stat|M\.Si|S\.E|S\.Kom|M\.M|A\.Md|Drs\.|Ir\.|Dr\.)\b/i', $lineContent) ||
                    (! str_contains(strtolower($lineContent), 'badan pusat statistik') && ! str_contains(strtolower($lineContent), 'bps'))) {
                    $findings[] = $this->finding(
                        'CATALOGUE.NO_INDIVIDUAL_NAMES',
                        $page,
                        trim($catMatch[0]),
                        'Diisi nama instansi BPS (contoh: Badan Pusat Statistik Kabupaten/Kota)',
                        'Sesuai Pedoman Pembuatan Publikasi BPS 2023 Bab 4 hal. 78, baris Penyusun, Penyunting, dan Pembuat Kover pada Halaman Katalog wajib diisi nama unit kerja institusi BPS, dilarang mencantumkan nama individu.',
                        'wajib'
                    );
                }
            }
        }

        // 5. TEAM.ENGLISH_PLURAL_RULE & TEAM.NO_NIP (Halaman Tim Penyusun)
        if ($pageType === 'team') {
            if (preg_match('/\b(Persons in Charge|Editors|Writers|Layouters|Translators)\b/u', $text, $pluralMatch)) {
                $singularMap = [
                    'Persons in Charge' => 'Person In Charge',
                    'Editors' => 'Editor',
                    'Writers' => 'Writer',
                    'Layouters' => 'Layouter',
                    'Translators' => 'Translator',
                ];
                $correctSingular = $singularMap[$pluralMatch[0]] ?? rtrim($pluralMatch[0], 's');
                $findings[] = $this->finding(
                    'TEAM.ENGLISH_PLURAL_RULE',
                    $page,
                    $pluralMatch[0],
                    $correctSingular,
                    'Gelar jabatan bahasa Inggris wajib menggunakan bentuk tunggal jika personilnya hanya satu orang ("'.$correctSingular.'").',
                    'wajib'
                );
            }

            if (preg_match('/\b(NIP\.?[:\s]*\d[\d\s]{7,25}\d|\b19\d{16}\b)/iu', $text, $nipMatch)) {
                $findings[] = $this->finding(
                    'TEAM.NO_NIP',
                    $page,
                    $nipMatch[0],
                    'Nama jelas tanpa pencantuman NIP',
                    'Sesuai Pedoman Pembuatan Publikasi BPS 2023 Bab 4 hal. 80, susunan anggota tim penyusun ditulis nama jelas tanpa mencantumkan NIP.',
                    'saran'
                );
            }
        }

        // 6. PREFACE.NO_NIP & PREFACE.BPS_FULL_TITLE (Kata Pengantar)
        if ($pageType === 'preface') {
            if (preg_match('/\b(NIP\.?[:\s]*\d[\d\s]{7,25}\d|\b19\d{16}\b)/iu', $text, $prefaceNip)) {
                $findings[] = $this->finding(
                    'PREFACE.NO_NIP',
                    $page,
                    $prefaceNip[0],
                    'Nama penandatangan tanpa pencantuman NIP',
                    'Sesuai Pedoman Pembuatan Publikasi BPS 2023 Bab 4 hal. 83, nama pejabat penandatangan Kata Pengantar dicetak tebal tanpa mencantumkan NIP.',
                    'saran'
                );
            }

            if (preg_match('/\b(Kepala\s+BPS\s+(?:Kabupaten|Kota)\s+[A-Za-z\s]+)/iu', $text, $shortTitle)) {
                $fullTitle = str_ireplace('Kepala BPS', 'Kepala Badan Pusat Statistik', $shortTitle[0]);
                $findings[] = $this->finding(
                    'PREFACE.BPS_FULL_TITLE',
                    $page,
                    $shortTitle[0],
                    $fullTitle,
                    'Penulisan jabatan penandatangan bahasa Indonesia dilarang disingkat "Kepala BPS", wajib ditulis lengkap "'.$fullTitle.'".',
                    'wajib'
                );
            }
        }

        // 7. TOC.SUBCHAPTER_NO_TRAILING_DOT & TOC.NO_DAFTAR_GRAFIK (Daftar Isi)
        if (in_array($pageType, ['contents', 'toc', 'table_list', 'figure_list'], true)) {
            if (preg_match('/(?:^|\n)\s*([1-9]\.\d+)\.\s+([A-Za-z]{3,})/u', $text, $tocSub)) {
                $findings[] = $this->finding(
                    'TOC.SUBCHAPTER_NO_TRAILING_DOT',
                    $page,
                    trim($tocSub[0]),
                    $tocSub[1].' '.$tocSub[2],
                    'Sesuai Pedoman Pembuatan Publikasi BPS 2023 Bab 4.3.6, nomor subbab pada Daftar Isi dilarang diakhiri tanda titik (seharusnya "'.$tocSub[1].' '.$tocSub[2].'").',
                    'wajib'
                );
            }

            if (preg_match('/\b(Daftar\s+Grafik|DAFTAR\s+GRAFIK|List\s+of\s+Charts)\b/iu', $text, $grafikMatch)) {
                $findings[] = $this->finding(
                    'TOC.NO_DAFTAR_GRAFIK',
                    $page,
                    $grafikMatch[0],
                    'Daftar Gambar / List of Figures',
                    'Sesuai Pedoman Pembuatan Publikasi BPS 2023 Bab 4.3.9 hal. 44, publikasi BPS WAJIB menggunakan istilah baku "Daftar Gambar" (bukan "Daftar Grafik").',
                    'wajib'
                );
            }
        }

        // 8. TABLE.NO_TRAILING_DOT & TABLE.NO_KATA_TAHUN & TABLE.EN_DASH_ZERO (Tabel)
        if (in_array($pageType, ['table', 'tables'], true) || ! empty($page['inventory']['tables'])) {
            // Cek nomor tabel dengan trailing dot (Tabel 1.1.)
            if (preg_match('/\b(Tabel\s+[1-9]\.\d+(?:\.\d+)?)\./iu', $text, $tblDot)) {
                $findings[] = $this->finding(
                    'TABLE.NO_TRAILING_DOT',
                    $page,
                    $tblDot[0],
                    $tblDot[1],
                    'Sesuai Pedoman Publikasi BPS 2023 hal. 40, nomor tabel dan judul tabel dilarang diakhiri tanda titik ("'.$tblDot[1].'").',
                    'wajib'
                );
            }

            // Cek kata "Tahun" pada judul tabel
            if (preg_match('/\b(Tabel\s+[1-9]\.\d+[^\n]+?)(?:,\s*|\s+)Tahun\s+(201\d|202\d)\b/iu', $text, $yrMatch)) {
                $findings[] = $this->finding(
                    'TABLE.NO_KATA_TAHUN',
                    $page,
                    $yrMatch[0],
                    rtrim($yrMatch[1], ', ').', '.$yrMatch[2],
                    'Sesuai Pedoman Publikasi BPS 2023 hal. 41 & Instrumen baris 145, judul tabel tidak perlu menampilkan kata "Tahun", cukup didahului tanda koma sebelum angka tahun (contoh: "'.rtrim($yrMatch[1], ', ').', '.$yrMatch[2].'").',
                    'wajib'
                );
            }

            // Cek sel angka 0 biasa alih-alih en dash (–)
            $lines = array_map('trim', explode("\n", $text));
            $bareZeros = [];
            foreach ($lines as $line) {
                // Abaikan baris nomor kolom seperti (1) (2) (0) atau penjelasan persen/tahun
                if (preg_match('/\([0-9]+\)/', $line) || str_contains($line, '%') || preg_match('/\b(?:persen|percent|tahun|year)\b/iu', $line)) {
                    continue;
                }
                if (in_array($line, ['0', '0,0', '0,00'], true)) {
                    $bareZeros[] = $line;
                } elseif (preg_match('/(?:\t|\s{2,})(0|0,0|0,00)(?:\t|\s{2,}|$)/', $line, $zm)) {
                    $bareZeros[] = $zm[1];
                } elseif (preg_match('/[A-Za-z]\s+(0|0,0|0,00)$/', $line, $zm)) {
                    $bareZeros[] = $zm[1];
                }
            }
            if (count($bareZeros) >= 1) {
                $findings[] = $this->finding(
                    'TABLE.EN_DASH_ZERO',
                    $page,
                    reset($bareZeros).' (ditemukan '.count($bareZeros).' sel nol biasa)',
                    '– (En Dash)',
                    'Sesuai Pedoman Pembuatan Publikasi BPS 2023 Bab 4 hal. 69 & Instrumen baris 156, nilai nol mutlak (nihil) WAJIB disajikan dengan tanda strip panjang En Dash ("–"), dilarang angka 0 atau 0,0.',
                    'wajib'
                );
            }

            // TABLE.TITLE (Kewajiban tahun data pada judul tabel)
            if (preg_match('/\b(Tabel\s+[1-9]\.\d+(?:\.\d+)?\s+[^\n]+)/iu', $text, $tblTitleLine)) {
                $titleContent = trim($tblTitleLine[1]);
                if (! preg_match('/\b(201\d|202\d|Tahun|Year)\b/iu', $titleContent) && ! str_contains($titleContent, '...') && strlen($titleContent) < 140) {
                    $findings[] = $this->finding(
                        'TABLE.TITLE',
                        $page,
                        $titleContent,
                        $titleContent . ', [Tahun]',
                        'Sesuai Pedoman Publikasi BPS 2023 hal. 41 & Instrumen baris 145, judul tabel wajib memuat rujukan waktu atau tahun data.',
                        'wajib'
                    );
                }
            }

            // TABLE.SYMBOL (Kewajiban keterangan Sumber di bawah tabel)
            if (preg_match('/\bTabel\s+[1-9]\.\d+/iu', $text)) {
                $hasSource = preg_match('/\b(?:Sumber|Source)\s*[:\/]/iu', $text);
                if (! $hasSource && strlen(trim($text)) > 60) {
                    $findings[] = $this->finding(
                        'TABLE.SYMBOL',
                        $page,
                        'Keterangan sumber data tidak ditemukan di bawah tabel.',
                        'Mencantumkan baris "Sumber: [Nama Instansi/BPS]" di bagian bawah tabel',
                        'Sesuai Pedoman Pembuatan Publikasi BPS 2023 Bab 4 hal. 42-43 & Instrumen baris 160, setiap tabel statistik wajib mencantumkan sumber data.',
                        'wajib'
                    );
                }
            }

            // LANGUAGE.BILINGUAL (Judul tabel dwibahasa dengan tanda slash)
            if (preg_match('#\b(Tabel\s+[1-9]\.\d+(?:\.\d+)?\s+[^/\n]{10,80})$#ium', $text, $monolingualM)) {
                $line = trim($monolingualM[1]);
                if (! str_contains($text, '/') && ! preg_match('/\b(?:Table|Figures)\b/iu', $text)) {
                    $findings[] = $this->finding(
                        'LANGUAGE.BILINGUAL',
                        $page,
                        $line,
                        $line . ' / [English Translation]',
                        'Sesuai Pedoman Publikasi BPS 2023 hal. 40, publikasi dwibahasa wajib menyajikan judul tabel dalam bahasa Indonesia dan bahasa Inggris yang dipisahkan garis miring (" / ").',
                        'wajib'
                    );
                }
            }
        }

        // 9. BPS.FULL_NAME (Kaidah penulisan lengkap Badan Pusat Statistik)
        if (in_array($pageType, ['front_cover', 'cover', 'title', 'catalogue', 'preface', 'back_cover'], true)) {
            if (preg_match('/\b(?:Diterbitkan\s+oleh|Penerbit|Publisher|Penyusun|Disusun\s+oleh|Editor)\s*[:\/]?\s*(BPS\s+(?:Kabupaten|Kota|Provinsi)[^\n\r]*)/iu', $text, $bpsM)) {
                $expected = str_ireplace('BPS', 'Badan Pusat Statistik', $bpsM[1]);
                $findings[] = $this->finding(
                    'BPS.FULL_NAME',
                    $page,
                    $bpsM[0],
                    $expected,
                    'Sesuai Pedoman Publikasi BPS §2.1, nama institusi pada penerbit/penyusun wajib ditulis lengkap "Badan Pusat Statistik", tidak disingkat BPS.',
                    'wajib'
                );
            }
        }

        // 10. CATALOGUE.COMPONENTS & CATALOGUE.LABELS & CATALOGUE.COPYRIGHT (Halaman Katalog)
        if ($pageType === 'catalogue') {
            $missingComponents = [];
            if (! preg_match('/\b(?:No\.?\s*Publikasi|Publication\s*Number)\b/iu', $text)) {
                $missingComponents[] = 'Nomor Publikasi';
            }
            if (! preg_match('/\b(?:No\.?\s*Katalog|Katalog\s*BPS|Catalog\s*Number)\b/iu', $text)) {
                $missingComponents[] = 'Nomor Katalog';
            }
            if (! preg_match('/\b\d{1,2}(?:[.,]\d+)?\s*[xX×]\s*\d{1,2}(?:[.,]\d+)?\s*cm\b/iu', $text)) {
                $missingComponents[] = 'Ukuran Buku (cm)';
            }
            if (! empty($missingComponents)) {
                $findings[] = $this->finding(
                    'CATALOGUE.COMPONENTS',
                    $page,
                    'Komponen KDT belum lengkap: hilang ' . implode(', ', $missingComponents),
                    'Halaman Katalog memuat blok KDT lengkap (No. Publikasi, No. Katalog, dan Ukuran Buku)',
                    'Sesuai Pedoman Pembuatan Publikasi BPS 2023 Bab 4 hal. 78, blok Katalog Dalam Terbitan wajib memuat Nomor Publikasi, Nomor Katalog, dan dimensi ukuran buku.',
                    'wajib'
                );
            }

            if (preg_match('/\bPenerbit\s*:/iu', $text) && ! preg_match('/\bPublished\s*by\b/iu', $text)) {
                $findings[] = $this->finding(
                    'CATALOGUE.LABELS',
                    $page,
                    'Label katalog "Penerbit:" tidak memiliki padanan dwibahasa Inggris "Published by:"',
                    'Penerbit/Published by:',
                    'Sesuai Pedoman Pembuatan Publikasi BPS 2023 Bab 4 hal. 78, label halaman katalog publikasi dwibahasa wajib disajikan dalam dua bahasa (Indonesia dan Inggris).',
                    'wajib'
                );
            }

            $hasCopyright = preg_match('/\b(Hak\s+Cipta|Copyright)\b/iu', $text);
            $hasBps = preg_match('/\b(Badan\s+Pusat\s+Statistik|BPS)\b/iu', $text);
            if (! $hasCopyright || ! $hasBps) {
                $findings[] = $this->finding(
                    'CATALOGUE.COPYRIGHT',
                    $page,
                    'Pernyataan hak cipta Badan Pusat Statistik tidak teridentifikasi pada halaman katalog.',
                    'Memuat klausul Hak Cipta Badan Pusat Statistik/Copyright Statistics Indonesia',
                    'Sesuai Pedoman Pembuatan Publikasi BPS 2023 Bab 4 hal. 78, halaman katalog wajib mencantumkan klausul perlindungan hak cipta Badan Pusat Statistik.',
                    'saran'
                );
            }
        }

        // 11. TEAM.FORMAT (Pembagian peran pada Halaman Tim Penyusun)
        if ($pageType === 'team') {
            $hasRole = preg_match('/\b(?:Penanggung\s*Jawab|Pengarah|Person[s]?\s*in\s*Charge|Penyunting|Editor[s]?|Pengolah\s*Data|Data\s*Processing|Penata\s*Letak|Layouter[s]?|Pembuat\s*Kover|Cover\s*Design)\b/iu', $text);
            if (! $hasRole && strlen(trim($text)) > 30) {
                $findings[] = $this->finding(
                    'TEAM.FORMAT',
                    $page,
                    'Susunan tim penyusun tidak memuat pembagian peran resmi.',
                    'Halaman memuat peran terstruktur: Penanggung Jawab, Penyunting, Pengolah Data, dan Penata Letak/Pembuat Kover',
                    'Sesuai Pedoman Pembuatan Publikasi BPS 2023 Bab 4 hal. 80, halaman Tim Penyusun wajib mencantumkan pembagian peran dan tanggung jawab yang jelas.',
                    'wajib'
                );
            }
        }

        // 12. PREFACE.FORMAT (Format Kata Pengantar dan Titimangsa)
        if ($pageType === 'preface') {
            $hasTitimangsa = preg_match('/\b[A-Z][a-z]+,\s*(?:Januari|Februari|Maret|April|Mei|Juni|Juli|Agustus|September|Oktober|November|Desember)\s+20\d\d\b/iu', $text);
            if (! $hasTitimangsa && strlen(trim($text)) > 60) {
                $findings[] = $this->finding(
                    'PREFACE.FORMAT',
                    $page,
                    'Baris titimangsa kota dan tanggal pengesahan belum teridentifikasi pada Kata Pengantar.',
                    'Mencantumkan baris tempat dan tanggal (contoh: "Ampana, September 2026")',
                    'Sesuai Pedoman Pembuatan Publikasi BPS 2023 Bab 4 hal. 83, halaman Kata Pengantar wajib memuat tempat dan waktu penandatanganan pengesahan.',
                    'wajib'
                );
            }
        }

        // 13. BIBLIOGRAPHY.FORMAT (Penyusunan alfabetis daftar pustaka)
        if ($pageType === 'bibliography') {
            $bibLines = array_filter(array_map('trim', explode("\n", $text)), fn ($l) => preg_match('/^[A-Z][a-zA-Z\s]+,\s*(?:19|20)\d\d\b/', $l));
            $firstLetters = [];
            foreach ($bibLines as $l) {
                $firstLetters[] = strtoupper($l[0]);
            }
            $isAlphabetical = true;
            for ($i = 0; $i < count($firstLetters) - 1; $i++) {
                if (strcmp($firstLetters[$i], $firstLetters[$i + 1]) > 0) {
                    $isAlphabetical = false;
                    break;
                }
            }
            if (! $isAlphabetical && count($firstLetters) >= 3) {
                $findings[] = $this->finding(
                    'BIBLIOGRAPHY.FORMAT',
                    $page,
                    'Urutan rujukan daftar pustaka tidak tersusun secara alfabetis (' . implode(', ', $firstLetters) . ').',
                    'Daftar pustaka disusun menurut abjad nama pengarang (A sampai Z)',
                    'Sesuai Pedoman Pembuatan Publikasi BPS 2023 Bab 4 hal. 87, rujukan pada daftar pustaka wajib disusun berurutan menurut alfabet nama pengarang.',
                    'saran'
                );
            }
        }

        // 14. OFFICIAL.REFERENCE (Domain rujukan eksternal BPS)
        if (preg_match_all('/(?:https?:\/\/|www\.)([a-zA-Z0-9.-]+\.[a-zA-Z]{2,})[^\s]*/i', $text, $urlMatches, PREG_SET_ORDER)) {
            foreach ($urlMatches as $um) {
                $host = strtolower($um[1]);
                if (preg_match('/\b(?:bps|sulteng|statistik)\b/i', $host) && ! str_ends_with($host, '.bps.go.id') && $host !== 'bps.go.id') {
                    $findings[] = $this->finding(
                        'OFFICIAL.REFERENCE',
                        $page,
                        $um[0],
                        'Portal rujukan resmi https://*.bps.go.id',
                        'Rujukan data publikasi BPS wajib menggunakan domain portal resmi BPS (.bps.go.id).',
                        'verifikasi'
                    );
                }
            }
        }

        return $findings;
    }

    public function finding(string $rule, array $page, string $observed, string $expected, string $recommendation, string $severity = 'wajib'): array
    {
        return [
            'rule_code' => $rule,
            'severity' => $severity,
            'observed' => $observed,
            'expected' => $expected,
            'recommendation' => $recommendation,
            'confidence' => .99,
            'source' => 'deterministic',
            'evidences' => [
                ['page' => $page['physical_page'] ?? 1, 'quote' => $observed, 'bbox' => null],
            ],
        ];
    }
}
