<?php

declare(strict_types=1);

namespace App\Audit;

use App\Models\Finding;
use App\Models\Publication;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

class WorkbookWriter
{
    public const SECTION_ORDER = [
        // 1. Kover depan & komparasi kover-judul
        'front_cover' => 10,
        'Kover depan' => 10,
        'Perbandingan visual kover dan judul' => 15,
        'Kover dan Judul' => 15,

        // 2. Halaman Judul
        'title' => 20,
        'Halaman Judul' => 20,
        'Halaman Judul Utama' => 20,

        // 3. Halaman Katalog & Riwayat Volume
        'catalogue' => 30,
        'Halaman katalog' => 30,
        'Kesesuaian volume publikasi' => 35,

        // 4. Tim Penyusun
        'team' => 40,
        'Halaman Tim Penyusun' => 40,

        // 5. Kata Pengantar
        'preface' => 50,
        'Kata pengantar' => 50,
        'Kata Pengantar dan Preface' => 50,

        // 6. Daftar Isi
        'contents' => 60,
        'Daftar Isi' => 60,

        // 7. Daftar Tabel / Gambar & Penomoran Romawi
        'table_list' => 70,
        'Daftar Tabel' => 70,
        'figure_list' => 80,
        'Daftar Gambar' => 80,
        'Daftar Tabel dan Daftar Gambar' => 75,
        'Daftar Tabel/Gambar/Grafik/Lampiran' => 85,
        'Penomoran' => 86,
        'Susunan halaman pendahuluan romawi' => 88,

        // 8. Layout dan Running Title
        'Layout dan running title' => 90,

        // 9. Bab 1 s.d. Bab 7 Resmi BPS
        'Bab 1 Geografi' => 101,
        'Bab 2 Pemerintahan' => 102,
        'Bab 3 Penduduk' => 103,
        'Bab 4 Sosial dan Kesejahteraan Rakyat' => 104,
        'Bab 5 Pertanian' => 105,
        'Bab 6 Pariwisata, Transportasi, dan Komunikasi' => 106,
        'Bab 7 Perbankan, Koperasi, dan Perdagangan' => 107,

        // 10. Batang Tubuh / Isi Publikasi Umum
        'chapter' => 110,
        'Penjelasan Bab' => 110,
        'narrative' => 115,
        'Ulasan Narasi' => 115,
        'table' => 120,
        'Tabel Data' => 120,
        'Layout Isi' => 130,
        'Pemeriksaan isi dan layout' => 140,
        'Pemeriksaan silang' => 145,

        // 11. Bagian Akhir Publikasi
        'bibliography' => 160,
        'Daftar Pustaka' => 160,
        'blank' => 170,
        'Halaman Kosong' => 170,
        'Susunan halaman putih akhir' => 175,

        // 12. Kover Belakang
        'back_cover' => 190,
        'Kover belakang' => 190,

        // Lain-lain / Tambahan Manual
        'Temuan manual' => 200,
        'Pemeriksaan' => 210,
    ];

    public const SECTION_LABELS = [
        'front_cover' => 'Kover depan',
        'title' => 'Halaman Judul Utama',
        'catalogue' => 'Halaman katalog',
        'team' => 'Halaman Tim Penyusun',
        'preface' => 'Kata pengantar',
        'contents' => 'Daftar Isi',
        'table_list' => 'Daftar Tabel',
        'figure_list' => 'Daftar Gambar',
        'chapter' => 'Penjelasan Bab',
        'narrative' => 'Ulasan Narasi',
        'table' => 'Tabel Data',
        'bibliography' => 'Daftar Pustaka',
        'blank' => 'Halaman Kosong',
        'back_cover' => 'Kover belakang',
    ];

    public function write(Publication $pub, string $path, ?array $coverage = null, array $options = []): array
    {
        if ($coverage === null) {
            $baseCoverage = app(AuditCoordinator::class)->coverage($pub);
            $summary = app(CoverageService::class)->summarize($pub);
            $coverage = array_merge($baseCoverage, [
                'mode' => 'full',
                'limitations' => $summary['limitations'],
                'additional_checks' => $summary['additional_checks'],
            ]);
        }

        $reportMode = $coverage['report_mode'] ?? ($coverage['mode'] ?? 'full');
        $partial = $reportMode === 'partial' || ($coverage['mode'] ?? '') === 'partial';
        $withLimitations = $reportMode === 'complete_with_limitations' || ($coverage['mode'] ?? '') === 'complete_with_limitations';
        $limitations = $coverage['limitations'] ?? [];
        $checkedPages = $coverage['checked_pages'] ?? ($coverage['page_coverage']['checked_pages'] ?? $pub->pages()->where('status', 'complete')->count());
        $totalPages = $coverage['total_pages'] ?? ($coverage['page_coverage']['total_pages'] ?? $pub->page_count);

        $disk = Storage::disk('local');
        $book = IOFactory::load($disk->path($pub->template_path));
        $sheet = $book->getActiveSheet();
        foreach ($book->getAllSheets() as $other) {
            if ($other !== $sheet) {
                $book->removeSheetByIndex($book->getIndex($other));
            }
        }
        foreach (array_keys($sheet->getHyperlinkCollection()) as $cell) {
            $sheet->setHyperlink($cell, null);
        }
        $sheet->setComments([]);
        // Preserve real template styling, never its old review text or hidden cell values.
        $detailStyle = $sheet->getStyle('M9')->exportArray();
        $labelStyle = $sheet->getStyle('L9')->exportArray();
        foreach ($sheet->getMergeCells() as $merge) {
            $range = Coordinate::rangeBoundaries($merge);
            if ($range[0][1] >= 7) {
                $sheet->unmergeCells($merge);
            }
        }
        $oldLast = $sheet->getHighestRow();
        if ($oldLast >= 7) {
            $sheet->removeRow(7, $oldLast - 6);
        }
        // Columns outside the report are not part of the template output.
        if (Coordinate::columnIndexFromString($sheet->getHighestColumn()) > 13) {
            $sheet->removeColumn('N', 100);
        }
        $sheet->setTitle(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', '', $pub->region).' '.$pub->year, 0, 31));
        foreach (['B5' => 1, 'C5' => $pub->title, 'D5' => 'Tahunan', 'E5' => 'Indonesia dan Inggris', 'F5' => '', 'G5' => now()->format('d-m-Y'), 'H5' => '', 'I5' => '', 'J5' => '', 'K5' => '', 'L5' => ''] as $cell => $value) {
            $sheet->setCellValueExplicit($cell, (string) $value, DataType::TYPE_STRING);
        }
        $chapterMap = $pub->manifest['chapter_map'] ?? null;
        if (! $chapterMap && config('audit.chapter_map_enabled', true)) {
            $rawPages = $pub->pages()->orderBy('physical_page')->get()->toArray();
            if (! empty($rawPages)) {
                $chapterMap = app(ChapterMapBuilder::class)->build($rawPages);
            }
        }

        $rawFindings = $pub->findings()
            ->where('deleted', false)
            ->get();

        $findings = $this->consolidateFindings($rawFindings)
            ->sort(function ($a, $b) use ($chapterMap) {
                $secA = $this->resolveSection($a, $chapterMap);
                $secB = $this->resolveSection($b, $chapterMap);
                $weightA = $this->sectionWeight($secA, $a->section);
                $weightB = $this->sectionWeight($secB, $b->section);
                if ($weightA !== $weightB) {
                    return $weightA <=> $weightB;
                }

                $pageA = $this->firstPhysicalPage($a);
                $pageB = $this->firstPhysicalPage($b);
                if ($pageA !== $pageB) {
                    return $pageA <=> $pageB;
                }

                return $a->id <=> $b->id;
            })
            ->values();
        $r = 7;
        if ($partial) {
            $sheet->mergeCells('L'.$r.':M'.$r);
            $sheet->setCellValueExplicit('L'.$r, 'EXCEL SEMENTARA — PEMERIKSAAN BELUM LENGKAP', DataType::TYPE_STRING);
            $sheet->getStyle('L'.$r.':M'.$r)->getFill()->setFillType('solid')->getStartColor()->setARGB('FFFFBDBD');
            $sheet->getStyle('L'.$r)->getFont()->setBold(true);
            $sheet->getRowDimension($r++)->setRowHeight(32);
            $notes = ['Cakupan '.$checkedPages.' dari '.$totalPages.' halaman selesai diperiksa. Pemeriksaan silang belum dinyatakan lengkap. Tidak boleh digunakan sebagai hasil final. Hasil tersimpan tidak dihapus; lanjutkan halaman yang belum selesai lalu buat versi lengkap.'];
            foreach ($coverage['unchecked_pages'] ?? [] as $page) {
                $notes[] = 'PDF hlm. '.$page['physical_page'].' belum selesai. Status: '.$page['status'].'. '.($page['error'] ?? 'Belum ada hasil pembacaan tervalidasi.');
            }
            foreach ($notes as $note) {
                foreach ($this->chunks($note) as $chunk) {
                    $sheet->getStyle('L'.$r)->applyFromArray($labelStyle);
                    $sheet->getStyle('M'.$r)->applyFromArray($detailStyle);
                    $sheet->setCellValueExplicit('L'.$r, 'Cakupan pemeriksaan', DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('M'.$r, $chunk, DataType::TYPE_STRING);
                    $sheet->getRowDimension($r)->setRowHeight(min(409, max(48, (int) ceil(mb_strlen($chunk) / 95) * 14 + 12)));
                    $sheet->getRowDimension($r++)->setOutlineLevel(1);
                }
            }
        } elseif ($withLimitations || ! empty($limitations)) {
            $sheet->mergeCells('L'.$r.':M'.$r);
            $sheet->setCellValueExplicit('L'.$r, 'EXCEL PEMERIKSAAN — DENGAN KETERBATASAN (PERLU VERIFIKASI MANUAL)', DataType::TYPE_STRING);
            $sheet->getStyle('L'.$r.':M'.$r)->getFill()->setFillType('solid')->getStartColor()->setARGB('FFFFF2CC');
            $sheet->getStyle('L'.$r)->getFont()->setBold(true);
            $sheet->getRowDimension($r++)->setRowHeight(32);
            $notes = ['Cakupan: Seluruh '.$checkedPages.' halaman selesai diperiksa. Namun, beberapa butir pemeriksaan aturan tambahan belum dapat diverifikasi secara otomatis dan memerlukan verifikasi manual.'];
            foreach ($limitations as $limitation) {
                $notes[] = (str_starts_with($limitation, 'Perlu verifikasi') ? $limitation : 'Perlu verifikasi: '.$limitation);
            }
            foreach ($notes as $note) {
                foreach ($this->chunks($note) as $chunk) {
                    $sheet->getStyle('L'.$r)->applyFromArray($labelStyle);
                    $sheet->getStyle('M'.$r)->applyFromArray($detailStyle);
                    $sheet->setCellValueExplicit('L'.$r, 'Keterbatasan pemeriksaan', DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('M'.$r, $chunk, DataType::TYPE_STRING);
                    $sheet->getRowDimension($r)->setRowHeight(min(409, max(48, (int) ceil(mb_strlen($chunk) / 95) * 14 + 12)));
                    $sheet->getRowDimension($r++)->setOutlineLevel(1);
                }
            }
        }
        $lastSection = null;
        foreach ($findings as $finding) {
            $section = $this->resolveSection($finding, $chapterMap);
            if ($section !== $lastSection) {
                $sheet->mergeCells('L'.$r.':M'.$r);
                $sheet->setCellValueExplicit('L'.$r, $section, DataType::TYPE_STRING);
                $sheet->getStyle('L'.$r.':M'.$r)->getFill()->setFillType('solid')->getStartColor()->setARGB('FFE6F2EF');
                $sheet->getStyle('L'.$r)->getFont()->setBold(true);
                $sheet->getRowDimension($r)->setRowHeight(24);
                $r++;
                $lastSection = $section;
            }
            $evidence = $this->formatEvidences($finding->evidences ?? []);

            $assessmentNote = '';
            $unreviewedAssessment = $finding->assessments()->whereNull('reviewed_at')->latest()->first();
            if ($unreviewedAssessment && in_array($unreviewedAssessment->proposed_decision, ['dismiss', 'cancel'], true)) {
                $assessmentNote = "\nUsulan pembatalan oleh sistem: ".$unreviewedAssessment->reason.' (menunggu keputusan peninjau)';
            }

            $isRejected = $finding->review_status === 'rejected';
            $headerTag = $isRejected
                ? '[DITOLAK · '.mb_strtoupper($finding->severity).' · '.$finding->rule_code.']'
                : '['.mb_strtoupper($finding->severity).' · '.$finding->rule_code.']';

            $noteLine = '';
            if ($isRejected) {
                $noteLine = "\nCatatan Penolakan Pemeriksa: ".($finding->review_note ?: 'Ditolak oleh pemeriksa');
            } elseif ($finding->review_note) {
                $noteLine = "\nCatatan pemeriksa: ".$finding->review_note;
            }

            $refTag = '';
            if (! empty($finding->consolidated_ids)) {
                $refTag = "\nRef: #F".implode(',#F', $finding->consolidated_ids);
            } elseif ($finding->id) {
                $refTag = "\nRef: #F".$finding->id;
            }

            $engineTag = '';
            if (! empty($options['include_engine_source'])) {
                $sourceLabel = match ($finding->detected_by ?? ($finding->source === 'machine' ? 'machine' : 'ai')) {
                    'machine' => 'Mesin Heuristik (Regex BPS)',
                    'both' => 'Mesin + Vision AI (Terkonfirmasi Bersama)',
                    default => 'Vision AI Multimodal',
                };
                $fusionLabel = ! empty($finding->fusion_status) ? ' · Status Fusion: '.$finding->fusion_status : '';
                $engineTag = "\nTerdeteksi Oleh: ".$sourceLabel.$fusionLabel;
            }

            $text = $headerTag."\nDitemukan: ".$finding->observed."\nSeharusnya: ".$finding->expected."\nRevisi: ".$finding->recommendation."\nBukti: ".$evidence.$engineTag.$noteLine.$assessmentNote.$refTag;
            // Split long evidence across rows so Excel does not clip text at its 409pt limit.
            $chunks = $this->chunks($text);
            foreach ($chunks as $i => $chunk) {
                $sheet->getStyle('L'.$r)->applyFromArray($labelStyle);
                $sheet->getStyle('M'.$r)->applyFromArray($detailStyle);
                $sheet->setCellValueExplicit('L'.$r, $i === 0 ? '•' : '↳', DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('M'.$r, $chunk, DataType::TYPE_STRING);
                $lines = 0;
                foreach (explode("\n", $chunk) as $line) {
                    $lines += max(1, (int) ceil(mb_strlen($line) / 95));
                }
                $sheet->getRowDimension($r)->setRowHeight(min(409, max(40, $lines * 14 + 12)));
                $sheet->getRowDimension($r)->setOutlineLevel(1);
                $r++;
            }
        }
        if ($findings->isEmpty()) {
            $sheet->mergeCells('L'.$r.':M'.$r);
            $sheet->setCellValueExplicit('L'.$r, $partial ? 'Belum ada temuan aktif yang tersimpan pada hasil sementara. Ini bukan pernyataan bahwa publikasi bebas kesalahan.' : 'Tidak ada temuan tervalidasi yang aktif. Semua halaman selesai diperiksa; keputusan akhir tetap melalui peninjauan.', DataType::TYPE_STRING);
            $sheet->getRowDimension($r++)->setRowHeight(48);
        }
        $last = $r - 1;
        $sheet->getStyle('B7:M'.$last)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FF444444');
        $sheet->getStyle('L7:M'.$last)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getColumnDimension('M')->setWidth(120);
        $sheet->getColumnDimension('L')->setWidth(22);
        $sheet->getPageSetup()->setPrintArea('B3:M'.$last)->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(3, 4);

        $descPrefix = match ($reportMode) {
            'partial' => 'SEMENTARA BELUM LENGKAP; ',
            'complete_with_limitations' => 'DENGAN KETERBATASAN (Perlu verifikasi); ',
            default => '',
        };
        $limitationsStr = ! empty($limitations) ? ' Keterbatasan: '.implode('; ', $limitations) : '';
        $book->getProperties()->setTitle($pub->title)->setDescription($descPrefix.'Aturan '.$pub->rule_version.'; revisi '.$pub->revision.'; cakupan '.$checkedPages.' dari '.$totalPages.' halaman.'.$limitationsStr);
        $disk->makeDirectory(dirname($path));
        IOFactory::createWriter($book, 'Xlsx')->save($disk->path($path));
        $book->disconnectWorksheets();
        $check = IOFactory::load($disk->path($path));
        $s = $check->getActiveSheet();
        for ($row = 7; $row <= $last; $row++) {
            for ($col = 2; $col <= 11; $col++) {
                if ($s->getCell([$col, $row])->getValue() !== null) {
                    throw new \RuntimeException('Nilai tersembunyi ditemukan di outline Excel.');
                }
            }
            if ($s->getStyle('M'.$row)->getBorders()->getBottom()->getBorderStyle() !== Border::BORDER_THIN) {
                throw new \RuntimeException('Border Excel tidak utuh.');
            }
        }
        foreach ($s->getCellCollection()->getCoordinates() as $cell) {
            if (in_array($s->getCell($cell)->getDataType(), [DataType::TYPE_ERROR, DataType::TYPE_FORMULA], true)) {
                throw new \RuntimeException('Formula atau error tidak diizinkan pada laporan.');
            }
        }
        $check->disconnectWorksheets();

        return $coverage + ['validated' => true, 'last_row' => $last, 'finding_count' => $findings->count(), 'checks' => ['blank_outline', 'continuous_borders', 'no_formulas', 'reopened_xlsx'], 'sha256' => hash_file('sha256', $disk->path($path))];
    }

    private function formatEvidences(array $evidences): string
    {
        $formatted = [];
        foreach ($evidences as $e) {
            $type = $e['type'] ?? (isset($e['reference_id']) || isset($e['artifact_type']) ? 'reference_artifact' : 'publication_page');
            if ($type === 'reference_artifact') {
                $provenanceLabel = match ($e['provenance'] ?? '') {
                    'official_browser' => 'Unduhan resmi BPS',
                    'user_upload' => 'Unggahan pengguna',
                    default => 'Dokumen rujukan',
                };
                $title = $e['target_title'] ?? 'Publikasi pembanding';
                $year = ! empty($e['target_year']) ? ' ('.$e['target_year'].')' : '';
                $header = "Rujukan: {$title}{$year} [{$provenanceLabel}]";
                if (! empty($e['url'])) {
                    $header .= " — URL: {$e['url']}";
                }
                if (! empty($e['artifact_type']) && $e['artifact_type'] === 'cover_image') {
                    $header .= ' (Kover rujukan)';
                } elseif (! empty($e['artifact_page'])) {
                    $header .= " (hlm. {$e['artifact_page']})";
                }
                if (! empty($e['quote'])) {
                    $header .= " — “{$e['quote']}”";
                }
                $formatted[] = $header;
            } else {
                $page = $e['physical_page'] ?? null;
                $printed = ! empty($e['printed_page']) ? " (tercetak {$e['printed_page']})" : '';
                $quote = ! empty($e['quote']) ? " — “{$e['quote']}”" : '';
                $formatted[] = ($page !== null ? "PDF hlm. {$page}" : 'Halaman').$printed.$quote;
            }
        }

        return implode('; ', $formatted);
    }

    /** Preserve every character while respecting both wrapping and explicit newlines. */
    private function chunks(string $text): array
    {
        $chunks = [];
        while ($text !== '') {
            $low = 1;
            $high = min(1600, mb_strlen($text));
            $length = 1;
            while ($low <= $high) {
                $mid = intdiv($low + $high, 2);
                $candidate = mb_substr($text, 0, $mid);
                $lines = array_sum(array_map(fn ($line) => max(1, (int) ceil(mb_strlen($line) / 95)), explode("\n", $candidate)));
                if ($lines <= 24) {
                    $length = $mid;
                    $low = $mid + 1;
                } else {
                    $high = $mid - 1;
                }
            }
            $chunks[] = mb_substr($text, 0, $length);
            $text = mb_substr($text, $length);
        }

        return $chunks;
    }

    public function resolveSection(Finding $finding, ?array $chapterMap = null): string
    {
        $raw = $finding->section ?: 'Pemeriksaan';

        // 1. Layout & Running Title issues
        if ($finding->rule_code === 'RUNNING.TITLE' || $finding->rule_code === 'PRINT.LAYOUT' || $raw === 'Layout dan running title') {
            return 'Layout dan running title';
        }

        // 2. Standard structural pages
        $standardMap = [
            'front_cover' => 'Kover depan',
            'Kover depan' => 'Kover depan',
            'title' => 'Halaman Judul Utama',
            'Halaman Judul' => 'Halaman Judul Utama',
            'Halaman Judul Utama' => 'Halaman Judul Utama',
            'catalogue' => 'Halaman katalog',
            'Halaman katalog' => 'Halaman katalog',
            'team' => 'Halaman Tim Penyusun',
            'Halaman Tim Penyusun' => 'Halaman Tim Penyusun',
            'preface' => 'Kata Pengantar dan Preface',
            'Kata pengantar' => 'Kata Pengantar dan Preface',
            'contents' => 'Daftar Isi',
            'Daftar Isi' => 'Daftar Isi',
            'table_list' => 'Daftar Tabel dan Daftar Gambar',
            'figure_list' => 'Daftar Tabel dan Daftar Gambar',
            'Daftar Tabel' => 'Daftar Tabel dan Daftar Gambar',
            'Daftar Gambar' => 'Daftar Tabel dan Daftar Gambar',
            'bibliography' => 'Daftar Pustaka',
            'Daftar Pustaka' => 'Daftar Pustaka',
            'back_cover' => 'Kover belakang',
            'Kover belakang' => 'Kover belakang',
        ];

        if (isset($standardMap[$raw])) {
            return $standardMap[$raw];
        }

        // 3. Resolve by authoritative chapter map if available
        if ($chapterMap) {
            $page = $this->firstPhysicalPage($finding);
            if ($page > 0 && $page < 999999) {
                $ch = app(ChapterMapBuilder::class)->resolveChapterForPage($chapterMap, $page);
                if ($ch) {
                    return $ch['label'];
                }
            }
        }

        // 4. For tables, narratives, chapters: fallback to detecting chapter 1 to 7 from content
        $corpus = mb_strtolower($finding->observed.' '.$finding->expected.' '.$finding->recommendation);
        foreach ($finding->evidences ?? [] as $ev) {
            $corpus .= ' '.mb_strtolower($ev['quote'] ?? '');
        }

        if (preg_match('/\b(tabel\s+1\b|bab\s+1\b|geografi|iklim|letak geografis|luas wilayah|kemiringan tanah)/u', $corpus)) {
            return 'Bab 1 Geografi';
        }
        if (preg_match('/\b(tabel\s+2\b|bab\s+2\b|pemerintahan|sls|desa|kelurahan|dprd|apbd|pajak|pbb)/u', $corpus)) {
            return 'Bab 2 Pemerintahan';
        }
        if (preg_match('/\b(tabel\s+3\b|bab\s+3\b|penduduk|kependudukan|piramida penduduk|sex ratio|rasio jenis kelamin|kepadatan penduduk)/u', $corpus)) {
            return 'Bab 3 Penduduk';
        }
        if (preg_match('/\b(tabel\s+4\b|bab\s+4\b|sosial|kesejahteraan|pendidikan|kesehatan|sekolah|guru|murid|madrasah|puskesmas|posyandu|apotek|bencana|agama)/u', $corpus)) {
            return 'Bab 4 Sosial dan Kesejahteraan Rakyat';
        }
        if (preg_match('/\b(tabel\s+5\b|bab\s+5\b|pertanian|tanaman|pangan|hortikultura|buah|sayur|perkebunan|peternakan|perikanan|luas panen|produksi)/u', $corpus)) {
            return 'Bab 5 Pertanian';
        }
        if (preg_match('/\b(tabel\s+6\b|bab\s+6\b|pariwisata|transportasi|komunikasi|hotel|jalan|pos|kendaraan|panjang jalan|akomodasi)/u', $corpus)) {
            return 'Bab 6 Pariwisata, Transportasi, dan Komunikasi';
        }
        if (preg_match('/\b(tabel\s+7\b|bab\s+7\b|perbankan|koperasi|perdagangan|bank|pasar|kud|ekonomi)/u', $corpus)) {
            return 'Bab 7 Perbankan, Koperasi, dan Perdagangan';
        }

        return self::SECTION_LABELS[$raw] ?? $raw;
    }

    private function sectionWeight(string $section, ?string $fallbackSection = null): int
    {
        if (isset(self::SECTION_ORDER[$section])) {
            return self::SECTION_ORDER[$section];
        }
        if (preg_match('/^Bab\s+(\d+)/i', $section, $m)) {
            return 100 + (int) $m[1];
        }

        return self::SECTION_ORDER[$fallbackSection] ?? 150;
    }

    /**
     * Consolidate high-repetition micro findings (e.g. RUNNING.TITLE, TABLE.SYMBOL)
     * using FindingConsolidator while preserving all page references.
     *
     * @param  \Illuminate\Support\Collection<int, Finding>  $findings
     * @return \Illuminate\Support\Collection<int, Finding>
     */
    private function consolidateFindings($findings)
    {
        return app(FindingConsolidator::class)->consolidate($findings);
    }

    private function firstPhysicalPage(Finding $finding): int
    {
        $min = null;
        foreach ($finding->evidences ?? [] as $ev) {
            $p = $ev['physical_page'] ?? $ev['page'] ?? null;
            if (is_numeric($p) && (int) $p > 0) {
                $min = $min === null ? (int) $p : min($min, (int) $p);
            }
        }

        return $min ?? 999999;
    }
}
