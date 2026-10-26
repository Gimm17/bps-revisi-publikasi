<?php

declare(strict_types=1);

namespace App\Audit;

use App\Models\PublicationPage;

class PageTypeClassifier
{
    /**
     * @return 'front_cover'|'title'|'catalogue'|'team'|'preface'|'contents'|'table_list'|'figure_list'|'chapter'|'table'|'narrative'|'bibliography'|'blank'|'back_cover'
     */
    public function classify(PublicationPage $page, int $totalPages): string
    {
        $physicalPage = $page->physical_page;
        $text = (string) ($page->text_layer ?? '');
        $cleanText = mb_strtoupper(trim($text));

        // 1. Kover Depan
        if ($physicalPage === 1) {
            return 'front_cover';
        }

        // 2. Kover Belakang
        if ($physicalPage === $totalPages && $totalPages > 1) {
            return 'back_cover';
        }

        // 3. Halaman Kosong (Blank Page)
        if (mb_strlen(trim($text)) < 20 && ! preg_match('/[A-Za-z0-9]/', $text)) {
            return 'blank';
        }

        // 4. Halaman Judul Utama (biasanya halaman fisik 2, 3, atau 4)
        if ($physicalPage <= 5 && str_contains($cleanText, 'DALAM ANGKA') && (! str_contains($cleanText, 'KATALOG'))) {
            if (str_contains($cleanText, 'BADAN PUSAT STATISTIK') || str_contains($cleanText, 'BPS') || str_contains($cleanText, 'STATISTICS')) {
                return 'title';
            }
        }

        // 5. Halaman Katalog BPS
        if (str_contains($cleanText, 'KATALOG BPS') || str_contains($cleanText, 'CATALOGUE IN PUBLICATION') || str_contains($cleanText, 'NO. PUBLIKASI') || str_contains($cleanText, 'NOMOR PUBLIKASI') || str_contains($cleanText, 'PUBLICATION NUMBER')) {
            return 'catalogue';
        }

        // 6. Tim Penyusun
        if (str_contains($cleanText, 'TIM PENYUSUN') || str_contains($cleanText, 'DRAFTING TEAM') || str_contains($cleanText, 'CONTRIBUTORS') || str_contains($cleanText, 'MANAGING EDITOR')) {
            return 'team';
        }

        // 7. Kata Pengantar / Sambutan
        if (str_contains($cleanText, 'KATA PENGANTAR') || str_contains($cleanText, 'PREFACE') || str_contains($cleanText, 'KATA SAMBUTAN')) {
            return 'preface';
        }

        // 8. Daftar Isi & Daftar Lainnya
        if (str_contains($cleanText, 'DAFTAR ISI') || str_contains($cleanText, 'TABLE OF CONTENTS')) {
            return 'contents';
        }
        if (str_contains($cleanText, 'DAFTAR TABEL') || str_contains($cleanText, 'LIST OF TABLES')) {
            return 'table_list';
        }
        if (str_contains($cleanText, 'DAFTAR GAMBAR') || str_contains($cleanText, 'DAFTAR GRAFIK') || str_contains($cleanText, 'LIST OF FIGURES')) {
            return 'figure_list';
        }

        // 9. Pembatas Bab
        if (preg_match('/^(?:BAB|CHAPTER)\s+[IVX0-9]+/mu', $cleanText) && mb_strlen($cleanText) < 250) {
            return 'chapter';
        }

        // 10. Daftar Pustaka
        if (str_contains($cleanText, 'DAFTAR PUSTAKA') || str_contains($cleanText, 'BIBLIOGRAPHY')) {
            return 'bibliography';
        }

        // 11. Tabel Statistik
        if (preg_match('/\bTabel\s+[0-9]+\.[0-9]+/i', $text) || preg_match('/\bTable\s+[0-9]+\.[0-9]+/i', $text)) {
            return 'table';
        }

        // 12. Default: Halaman Narasi
        return 'narrative';
    }
}
