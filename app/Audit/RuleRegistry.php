<?php

declare(strict_types=1);

namespace App\Audit;

class RuleRegistry
{
    public function all(): array
    {
        return [
            'ISSN.COLON' => 'Format titik dua pada ISSN', 'ISSN.CHECKSUM' => 'Digit kontrol ISSN',
            'BPS.FULL_NAME' => 'Nama lengkap Badan Pusat Statistik', 'IDENTITY.CONSISTENCY' => 'Konsistensi identitas antarhalaman',
            'COVER.COMPONENTS' => 'Kelengkapan dan hierarki kover', 'COVER.LOGO.NO_EFFECT' => 'Logo resmi tanpa efek digital',
            'COVER.ILLUSTRATION' => 'Sumber ilustrasi dan kontras kover', 'BACK.BARCODE' => 'Barcode ISSN di kover belakang',
            'CATALOGUE.COMPONENTS' => 'Komponen halaman katalog', 'CATALOGUE.LABELS' => 'Label katalog dwibahasa',
            'CATALOGUE.PAGE_COUNT' => 'Jumlah halaman hingga konten terakhir', 'CATALOGUE.COPYRIGHT' => 'Hak cipta dan penerbit',
            'TEAM.FORMAT' => 'Tim penyusun dan jabatan', 'PREFACE.FORMAT' => 'Kata pengantar dan tanda tangan',
            'CONTENTS.MATCH' => 'Daftar isi/tabel/gambar sesuai isi', 'CHAPTER.START' => 'Awal bagian dan pembatas bab',
            'RUNNING.TITLE' => 'Running title ganjil/genap', 'PAGINATION.ORDER' => 'Urutan nomor halaman',
            'TABLE.NUMBER' => 'Penomoran tabel dan kolom', 'TABLE.TITLE' => 'Judul, satuan, dan waktu tabel',
            'TABLE.FORMAT' => 'Format, alignment, dan garis tabel', 'TABLE.SYMBOL' => 'Simbol, superskrip, catatan, dan sumber',
            'TABLE.TOTAL' => 'Konsistensi total dan komponen', 'TABLE.CONSISTENCY' => 'Konsistensi data antartabel',
            'FIGURE.FORMAT' => 'Nomor, judul, sumber, dan satuan gambar', 'FIGURE.DATA' => 'Kesesuaian grafik dan data',
            'NARRATIVE.TABLE_MISMATCH' => 'Kesesuaian klaim narasi ulasan dengan data tabel',
            'LANGUAGE.BILINGUAL' => 'Tata letak dan terjemahan dwibahasa', 'LANGUAGE.EJAAN' => 'Ejaan dan penulisan',
            'BIBLIOGRAPHY.FORMAT' => 'Daftar pustaka dan penutup', 'VISUAL.LAYOUT' => 'Detail layout dan keterbacaan',
            'VISUAL.TYPOGRAPHY' => 'Konsistensi tipografi', 'MANUAL.REVIEW' => 'Temuan manual pemeriksa',
            'VOLUME.HISTORY' => 'Kesesuaian volume publikasi terhadap edisi sebelumnya',
            'TITLE.COVER_LAYOUT' => 'Kesamaan tata letak halaman judul dengan kover depan',
            'TITLE.WHITE_BACKGROUND' => 'Halaman judul berlatar putih bersih tanpa ilustrasi kover',
            'PRINT.ROMAN_END' => 'Halaman pendahuluan romawi berakhir genap',
            'PRINT.TRAILING_BLANKS' => 'Kesesuaian halaman putih akhir sebelum kover belakang',
            'COVER.TITLE.LAYOUT' => 'Kesamaan tata letak halaman judul dengan kover depan',
            'OFFICIAL.REFERENCE' => 'Validasi rujukan publikasi resmi BPS',

            // 15 Aturan Baru Spesifik Teradopsi dari Standar Pedoman BPS 2023 & web_v2
            'IDENTITY.WILAYAH_MISMATCH' => 'Deteksi ketidaksesuaian identitas wilayah antarseksi (District Mismatch)',
            'DOCUMENT.RAW_TEMPLATE' => 'Deteksi naskah draf template master BPS mentah',
            'CATALOGUE.NO_INDIVIDUAL_NAMES' => 'Larangan pencantuman nama individu pada halaman katalog',
            'CATALOGUE.ISSN_COLON_RULE' => 'Format penulisan ISSN wajib titik dua pada halaman katalog',
            'TEAM.ENGLISH_PLURAL_RULE' => 'Kaidah bentuk tunggal jabatan bahasa Inggris pada tim penyusun',
            'TEAM.NO_NIP' => 'Larangan pencantuman NIP pada susunan tim penyusun',
            'PREFACE.NO_NIP' => 'Larangan pencantuman NIP pada penandatangan kata pengantar',
            'PREFACE.TITIMANGSA_YEAR' => 'Sinkronisasi tahun titimangsa tanda tangan kata pengantar',
            'PREFACE.BPS_FULL_TITLE' => 'Penulisan lengkap tanpa singkatan jabatan pimpinan kata pengantar',
            'TOC.SUBCHAPTER_NO_TRAILING_DOT' => 'Larangan tanda titik di akhir nomor subbab daftar isi',
            'TOC.NO_DAFTAR_GRAFIK' => 'Penggunaan istilah resmi Daftar Gambar (bukan Daftar Grafik)',
            'TABLE.NO_TRAILING_DOT' => 'Larangan tanda titik di akhir nomor dan judul tabel',
            'TABLE.NO_KATA_TAHUN' => 'Larangan kata "Tahun" pada judul tabel statistik',
            'TABLE.EN_DASH_ZERO' => 'Standar notasi angka nol mutlak menggunakan En Dash (–)',
            'TYPOGRAPHY.SUPERSCRIPT_UNIT' => 'Penulisan simbol kuadrat superskrip pada satuan luas (km² dan m²)',
        ];
    }

    public function guidance(?string $version = null): string
    {
        $version ??= config('audit.rule_version');
        $file = $version === config('audit.target_rule_version') ? 'audit-rules/pedoman-v2.md' : 'audit-rules/pedoman.md';
        if (! file_exists(base_path($file))) {
            $file = 'audit-rules/pedoman.md';
        }

        return file_get_contents(base_path($file));
    }
}
