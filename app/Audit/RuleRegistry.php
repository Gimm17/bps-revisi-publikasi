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
            'LANGUAGE.BILINGUAL' => 'Tata letak dan terjemahan dwibahasa', 'LANGUAGE.EJAAN' => 'Ejaan dan penulisan',
            'BIBLIOGRAPHY.FORMAT' => 'Daftar pustaka dan penutup', 'VISUAL.LAYOUT' => 'Detail layout dan keterbacaan',
            'VISUAL.TYPOGRAPHY' => 'Konsistensi tipografi', 'MANUAL.REVIEW' => 'Temuan manual pemeriksa',
        ];
    }

    public function guidance(): string
    {
        return file_get_contents(base_path('audit-rules/pedoman.md'));
    }
}
