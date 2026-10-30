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
        preg_match_all('/ISSN\s*(:?)\s*(\d{4}[-\.]\s*[\dX]{4})/i', $text, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $hasColon = ($match[1] === ':');
            $normalizedIssn = preg_replace('/\s+/', '', str_replace('.', '-', $match[2]));
            $hasDotSeparator = str_contains($match[2], '.');
            if ($pageType === 'catalogue' && (! $hasColon || $hasDotSeparator || preg_match('/ISSN\s+:/i', $match[0]))) {
                $expected = 'ISSN: '.$normalizedIssn;
                $findings[] = $this->finding('CATALOGUE.ISSN_COLON_RULE', $page, $match[0], $expected, 'Pada Halaman Katalog, penulisan ISSN wajib menggunakan tanda titik dua rapat dan tanda hubung standar: '.$expected.'.');
            } elseif ($pageType !== 'catalogue' && ($hasColon || $hasDotSeparator)) {
                $expected = 'ISSN '.$normalizedIssn;
                $findings[] = $this->finding('ISSN.COLON', $page, $match[0], $expected, 'Di luar halaman katalog (termasuk Kover Depan, Halaman Judul, dan Tim Penyusun), penulisan ISSN wajib tanpa tanda titik dua dan menggunakan tanda hubung: '.$expected.'.');
            }

            // ISSN.CHECKSUM (Modulo 11 ISO 3297)
            $digits = str_replace('-', '', strtoupper($normalizedIssn));
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

        // 1b. COVER, TITLE & CATALOGUE: Pemeriksaan Nomor Katalog, Volume, Tahun, dan Bahasa Inggris (Italic & Tanpa 'OF')
        if (in_array($pageType, ['front_cover', 'cover', 'title'], true) && strlen(trim($text)) > 20) {
            if (! preg_match('/\b(?:Katalog|Catalogue)\b/iu', $text)) {
                $findings[] = $this->finding(
                    'COVER.COMPONENTS',
                    $page,
                    'Nomor Katalog tidak ditemukan pada '.($pageType === 'title' ? 'Halaman Judul Utama' : 'Kover Depan').'.',
                    'Katalog/Catalogue: 1102001.xxxxxxx (di sudut kanan atas, kata Catalogue dicetak miring/italic)',
                    'Sesuai Pedoman Pembuatan Publikasi BPS 2023 §2.2, cantumkan nomor katalog resmi di sudut kanan atas dengan format dwibahasa "Katalog/Catalogue: 1102001.xxxxxxx" (tanpa spasi sebelum titik dua dan kata Catalogue dicetak miring/italic).',
                    'wajib'
                );
            } else {
                if (preg_match('/\bKatalog\s*(?:\/\s*Catalogue)?\s+:\s*[0-9.]+/iu', $text, $catSpaceM)) {
                    $cleanCat = preg_replace('/\s*\/\s*/', '/', preg_replace('/\s+:/', ':', $catSpaceM[0]));
                    if (! str_contains(strtolower($cleanCat), 'catalogue')) {
                        $cleanCat = preg_replace('/^Katalog:/i', 'Katalog/Catalogue:', $cleanCat);
                    }
                    $findings[] = $this->finding(
                        'CATALOGUE.LABELS',
                        $page,
                        trim($catSpaceM[0]),
                        $cleanCat.' (kata Catalogue wajib dicetak miring/italic)',
                        'Sesuai Pedoman Pembuatan Publikasi BPS 2023 §2.2, hapus spasi sebelum tanda titik dua pada label nomor katalog dan pastikan kata "Catalogue" dicetak miring (italic).',
                        'wajib'
                    );
                } elseif (preg_match('/\b(Katalog\s+\/\s*Catalogue|Katalog\s*\/\s+Catalogue)\s*:\s*[0-9.]+/iu', $text, $catSlashM)) {
                    $cleanCat = preg_replace('/\s*\/\s*/', '/', $catSlashM[0]);
                    $findings[] = $this->finding(
                        'CATALOGUE.LABELS',
                        $page,
                        trim($catSlashM[0]),
                        $cleanCat.' (kata Catalogue wajib dicetak miring/italic)',
                        'Sesuai Pedoman Pembuatan Publikasi BPS 2023 §2.2, hapus spasi di sekitar tanda garis miring pada label "Katalog/Catalogue:" dan pastikan kata "Catalogue" dicetak miring (italic).',
                        'wajib'
                    );
                } elseif (preg_match('/\bKatalog\s*:\s*([0-9.]+)/iu', $text, $catMonoM) && ! preg_match('/\bCatalogue\b/iu', $text)) {
                    $findings[] = $this->finding(
                        'CATALOGUE.LABELS',
                        $page,
                        trim($catMonoM[0]),
                        'Katalog/Catalogue: '.$catMonoM[1].' (kata Catalogue dicetak miring/italic)',
                        'Pada publikasi dwibahasa, label nomor katalog wajib menggunakan format dwibahasa "Katalog/Catalogue: '.$catMonoM[1].'" dengan kata "Catalogue" dicetak miring (italic).',
                        'wajib'
                    );
                }
            }

            if (! preg_match('/\bVolume?\s+\d+/iu', $text)) {
                $findings[] = $this->finding(
                    'COVER.COMPONENTS',
                    $page,
                    'Keterangan nomor volume penerbitan belum dicantumkan pada '.($pageType === 'title' ? 'Halaman Judul Utama' : 'Kover Depan').'.',
                    'Volume XX, 2026',
                    'Sesuai Pedoman Pembuatan Publikasi BPS 2023 §2.6, publikasi rutin ber-ISSN wajib mencantumkan nomor volume dan tahun terbitan (contoh: "Volume 17, 2026").',
                    'wajib'
                );
            }

            if (preg_match('/\b(202\d\s*\/\s*202\d)\b/', $text, $slashYearM)) {
                $findings[] = $this->finding(
                    'COVER.COMPONENTS',
                    $page,
                    'Tahun publikasi pada judul ditulis menggunakan garis miring: "'.$slashYearM[1].'".',
                    '2026',
                    'Sesuai Pedoman Pembuatan Publikasi BPS 2023 §2.5, judul publikasi tahunan hanya mencantumkan satu angka tahun rilis/data terbaru (2026) tanpa garis miring.',
                    'wajib'
                );
            }

            if (! preg_match('/\b(?:in\s+Figures|Figures)\b/iu', $text)) {
                $findings[] = $this->finding(
                    'LANGUAGE.BILINGUAL',
                    $page,
                    'Judul publikasi pada '.($pageType === 'title' ? 'Halaman Judul Utama' : 'Kover Depan').' belum memuat terjemahan bahasa Inggris.',
                    'Memuat judul bahasa Inggris yang dicetak miring (italic), contoh: "... Subdistrict/District in Figures 2026"',
                    'Sesuai Pedoman Pembuatan Publikasi BPS 2023 §2.4, publikasi dwibahasa wajib mencantumkan judul bahasa Inggris yang dicetak miring (italic) di bawah judul bahasa Indonesia.',
                    'wajib'
                );
            }

            if (preg_match('/\bBadan\s+Pusat\s+Statistik\b/iu', $text) && ! preg_match('/\bBPS\s*-\s*Statistics\b/iu', $text)) {
                $findings[] = $this->finding(
                    'LANGUAGE.BILINGUAL',
                    $page,
                    'Nama penerbit pada '.($pageType === 'title' ? 'Halaman Judul Utama' : 'Kover Depan').' hanya tertulis dalam bahasa Indonesia tanpa padanan bahasa Inggris.',
                    'Memuat nama penerbit bahasa Inggris dengan huruf Arial Bold Italic (contoh: "BPS-Statistics ... Regency/Municipality")',
                    'Sesuai Pedoman Pembuatan Publikasi BPS 2023 §2.7, di bawah nama Badan Pusat Statistik wajib dicantumkan nama resmi bahasa Inggris yang dicetak miring (italic).',
                    'wajib'
                );
            }
        }

        if (in_array($pageType, ['front_cover', 'cover', 'title', 'catalogue', 'team'], true)) {
            if (preg_match('/\b(VOLUM\s+\d+(?:\s*,\s*20\d\d)?)\b/iu', $text, $volTypoM)) {
                $fixedVol = preg_replace('/^VOLUM\b/iu', 'Volume', $volTypoM[1]);
                $findings[] = $this->finding(
                    'LANGUAGE.EJAAN',
                    $page,
                    $volTypoM[1],
                    $fixedVol,
                    'Terdapat salah ketik pada penulisan kata "VOLUM" (kurang huruf E). Perbaiki menjadi "'.$fixedVol.'".',
                    'wajib'
                );
            }

            if (preg_match('/\b(BPS\s*-\s*Statistics\s+of\s+[A-Za-z\s\-]+?(?:Regency|Municipality|Province))\b/iu', $text, $bpsOfM)) {
                $cleanBpsEn = preg_replace('/\b(BPS\s*-\s*Statistics)\s+of\s+/iu', '$1 ', $bpsOfM[1]);
                $findings[] = $this->finding(
                    'BPS.FULL_NAME',
                    $page,
                    $bpsOfM[1],
                    $cleanBpsEn.' (dicetak miring/italic tanpa kata "of")',
                    'Sesuai Pedoman Pembuatan Publikasi BPS 2023 §2.7 & §5.7, nomenklatur bahasa Inggris resmi BPS daerah tidak menggunakan preposisi "of" dan wajib dicetak miring (italic): "'.$cleanBpsEn.'".',
                    'wajib'
                );
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
            if (preg_match('/(?:Penyusun\s+Naskah(?:\s*\/\s*Manuscript\s+Drafter)?|Penyunting(?:\s*\/\s*Editor)?|Pembuat\s+Kover(?:\s*\/\s*Cover\s+Designer)?)\s*:\s*([^\n\r]+|\r?\n\s*[^\n\r]+)/iu', $text, $catMatch)) {
                $lineContent = trim($catMatch[1]);
                if ($lineContent !== '' && (
                    preg_match('/\b(S\.Si|S\.Tr\.Stat|S\.Stat|M\.Si|S\.E|S\.Kom|M\.M|A\.Md|Drs\.|Ir\.|Dr\.)\b/i', $lineContent) ||
                    (! str_contains(strtolower($lineContent), 'badan pusat statistik') && ! str_contains(strtolower($lineContent), 'bps') && ! str_contains(strtolower($lineContent), 'statistics'))
                )) {
                    $findings[] = $this->finding(
                        'CATALOGUE.NO_INDIVIDUAL_NAMES',
                        $page,
                        trim($catMatch[0]),
                        'Diisi nama instansi BPS lengkap (contoh: Badan Pusat Statistik Kabupaten/Kota)',
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
            if (preg_match('/\b(?:Diterbitkan\s+oleh|Penerbit|Publisher|Penyusun(?:\s+Naskah)?|Manuscript\s+Drafter|Penyunting|Disusun\s+oleh|Editor|Pembuat\s+Kover|Cover\s+Designer|Dicetak\s+oleh|Printed\s+by|Sumber\s+Ilustrasi|Illustration\s+Source)\s*[:\/]?\s*(?:\r?\n\s*)?(BPS\s+(?:Kabupaten|Kota|Provinsi)\s+[^\n\r\/]+)/iu', $text, $bpsM)) {
                $shortBps = trim($bpsM[1]);
                $expected = preg_replace('/^BPS\b/iu', 'Badan Pusat Statistik', $shortBps);
                $findings[] = $this->finding(
                    'BPS.FULL_NAME',
                    $page,
                    $shortBps,
                    $expected,
                    'Sesuai Pedoman Pembuatan Publikasi BPS 2023 §2.7 & §5.7, dalam bahasa Indonesia nama institusi tidak boleh disingkat menjadi "BPS", wajib ditulis lengkap "'.$expected.'".',
                    'wajib'
                );
            }
        }

        // 10. CATALOGUE.COMPONENTS & CATALOGUE.LABELS & CATALOGUE.PAGE_COUNT & CATALOGUE.COPYRIGHT (Halaman Katalog)
        if ($pageType === 'catalogue') {
            $missingComponents = [];
            if (! preg_match('/\b(?:No(?:mor)?\.?\s*Publikasi|Publication\s*Number)\b/iu', $text)) {
                $missingComponents[] = 'Nomor Publikasi';
            }
            if (! preg_match('/\b(?:No(?:mor)?\.?\s*Katalog|Katalog\s*(?:\/\s*Catalogue)?\s*:|Katalog\s*BPS|Catalog(?:ue)?\s*Number)\b/iu', $text)) {
                $missingComponents[] = 'Nomor Katalog';
            }
            if (! preg_match('/\b\d{1,2}(?:[.,]\d+)?\s*(?:cm\s*)?[xX×]\s*\d{1,2}(?:[.,]\d+)?\s*cm\b/iu', $text)) {
                $missingComponents[] = 'Ukuran Buku (cm)';
            }
            if (! empty($missingComponents)) {
                $findings[] = $this->finding(
                    'CATALOGUE.COMPONENTS',
                    $page,
                    'Komponen KDT belum lengkap: hilang ' . implode(', ', $missingComponents),
                    'Halaman Katalog memuat blok KDT lengkap (Nomor Katalog, Nomor Publikasi, dan Ukuran Buku)',
                    'Sesuai Pedoman Pembuatan Publikasi BPS 2023 Bab 4 hal. 78, halaman katalog wajib memuat Nomor Katalog, Nomor Publikasi, dan dimensi ukuran buku.',
                    'wajib'
                );
            }

            if (preg_match('/\b(Katalog\s+\/\s*Catalogue|Katalog\s*\/\s+Catalogue|Nomor\s+Publikasi\s+\/\s*Publication\s+Number|Ukuran\s+Buku\s+\/\s*Book\s+Size|Jumlah\s+Halaman\s+\/\s*Number\s+of\s+Pages)\s*:/iu', $text, $catSlashLabelM)) {
                $cleanLabel = preg_replace('/\s*\/\s*/', '/', $catSlashLabelM[0]);
                $findings[] = $this->finding(
                    'CATALOGUE.LABELS',
                    $page,
                    trim($catSlashLabelM[0]),
                    $cleanLabel.' (padanan bahasa Inggris dicetak miring/italic)',
                    'Sesuai Pedoman Pembuatan Publikasi BPS 2023 §5.2, hapus spasi di sekitar garis miring pada label katalog dwibahasa ("'.$cleanLabel.'") dan pastikan istilah bahasa Inggris dicetak miring (italic).',
                    'wajib'
                );
            }

            if (preg_match('/\b((?:Katalog(?:\/Catalogue)?|Nomor\s+Publikasi(?:\/Publication\s+Number)?|Ukuran\s+Buku(?:\/Book\s+Size)?|Jumlah\s+Halaman(?:\/Number\s+of\s+Pages)?)\s+:)/iu', $text, $catColonSpaceM)) {
                $cleanColonLabel = preg_replace('/\s+:$/', ':', $catColonSpaceM[1]);
                $findings[] = $this->finding(
                    'CATALOGUE.LABELS',
                    $page,
                    trim($catColonSpaceM[1]),
                    $cleanColonLabel,
                    'Sesuai Pedoman Pembuatan Publikasi BPS 2023 §5.2, tanda titik dua pada label halaman katalog wajib ditulis rapat tanpa spasi di depannya ("'.$cleanColonLabel.'").',
                    'wajib'
                );
            }

            if (preg_match('/\bJumlah\s+Halaman(?:\s*\/\s*Number\s+of\s+Pages)?\s*:\s*([^\n\r]+)/iu', $text, $pageCountLineM)) {
                $pcVal = trim($pageCountLineM[1]);
                $hasPlaceholderXx = (bool) preg_match('/^xx\b/i', $pcVal);
                $hasPlusSpace = (bool) preg_match('/\s+\+|\+\s+/', $pcVal);
                $hasShortHal = (bool) preg_match('/\bhal(?:\b|\/)/i', $pcVal);
                if ($hasPlaceholderXx || $hasPlusSpace || $hasShortHal) {
                    $normalizedPc = preg_replace('/\s*\+\s*/', '+', $pcVal);
                    $normalizedPc = preg_replace('/\bhal\b/i', 'halaman', $normalizedPc);
                    if (! str_contains(strtolower($normalizedPc), 'pages')) {
                        $normalizedPc .= '/pages';
                    }
                    $findings[] = $this->finding(
                        'CATALOGUE.PAGE_COUNT',
                        $page,
                        trim($pageCountLineM[0]),
                        'Jumlah Halaman/Number of Pages: '.$normalizedPc.' (kata Number of Pages dan pages dicetak miring/italic)',
                        'Sesuai Pedoman Pembuatan Publikasi BPS 2023 §5.5, jumlah halaman ditulis rapat tanpa spasi di kiri/kanan tanda "+", kata "halaman" dilarang disingkat menjadi "hal", angka romawi diisi jumlah halaman pendahuluan sebenarnya (bukan placeholder "xx"), dan kata "pages" dicetak miring (italic).',
                        'wajib'
                    );
                }
            }

            if (preg_match('/(?:^|\n)\s*(Penerbit(?:\s*\/\s*Publisher)?)\s*(?:\r?\n)/iu', $text, $pubNoColonM) && ! str_contains($pubNoColonM[1], ':')) {
                $findings[] = $this->finding(
                    'CATALOGUE.LABELS',
                    $page,
                    trim($pubNoColonM[1]),
                    'Penerbit/Publisher: (diakhiri tanda titik dua dan kata Publisher dicetak miring/italic)',
                    'Sesuai Pedoman Pembuatan Publikasi BPS 2023 §5.7, label "Penerbit/Publisher:" wajib menggunakan tanda titik dua rapat dan kata "Publisher" dicetak miring (italic).',
                    'wajib'
                );
            } elseif (preg_match('/\bPenerbit\s*:/iu', $text) && ! preg_match('/\b(?:Publisher|Published\s*by)\b/iu', $text)) {
                $findings[] = $this->finding(
                    'CATALOGUE.LABELS',
                    $page,
                    'Label katalog "Penerbit:" tidak memiliki padanan dwibahasa Inggris.',
                    'Penerbit/Publisher: (kata Publisher dicetak miring/italic)',
                    'Sesuai Pedoman Pembuatan Publikasi BPS 2023 Bab 4 hal. 78, label halaman katalog publikasi dwibahasa wajib disajikan dalam dua bahasa (Indonesia dan Inggris yang dicetak miring).',
                    'wajib'
                );
            }

            if (preg_match('/(?:©|)\s*(BPS\s+(?:Kabupaten|Kota|Provinsi)\s+[^\n\r\/]+)/iu', $text, $copyShortM)) {
                $fullCopyBps = preg_replace('/^BPS\b/iu', 'Badan Pusat Statistik', trim($copyShortM[1]));
                $findings[] = $this->finding(
                    'CATALOGUE.COPYRIGHT',
                    $page,
                    trim($copyShortM[0]),
                    '©'.$fullCopyBps,
                    'Sesuai Pedoman Pembuatan Publikasi BPS 2023 §5.7, penulisan penerbit menggunakan simbol hak cipta rapat tanpa spasi dan nama lengkap "Badan Pusat Statistik" (tidak disingkat BPS): "©'.$fullCopyBps.'".',
                    'wajib'
                );
            }

            $hasCopyright = preg_match('/\b(Hak\s+Cipta|Copyright|Dilarang\s+mereproduksi|prohibited\s+to\s+reproduce)\b/iu', $text);
            $hasBps = preg_match('/\b(Badan\s+Pusat\s+Statistik|BPS)\b/iu', $text);
            if (! $hasCopyright || ! $hasBps) {
                $findings[] = $this->finding(
                    'CATALOGUE.COPYRIGHT',
                    $page,
                    'Pernyataan hak cipta Badan Pusat Statistik tidak teridentifikasi pada halaman katalog.',
                    'Memuat klausul larangan reproduksi komersial / Hak Cipta Badan Pusat Statistik dalam bahasa Indonesia dan Inggris (italic)',
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
