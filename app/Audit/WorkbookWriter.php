<?php

declare(strict_types=1);

namespace App\Audit;

use App\Models\Publication;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

class WorkbookWriter
{
    public function write(Publication $pub, string $path): array
    {
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
        $findings = $pub->findings()->where('deleted', false)->where('review_status', '!=', 'rejected')->orderBy('section')->orderBy('id')->get();
        $r = 7;
        $lastSection = null;
        foreach ($findings as $finding) {
            $section = $finding->section ?: 'Pemeriksaan';
            if ($section !== $lastSection) {
                $sheet->mergeCells('L'.$r.':M'.$r);
                $sheet->setCellValueExplicit('L'.$r, $section, DataType::TYPE_STRING);
                $sheet->getStyle('L'.$r.':M'.$r)->getFill()->setFillType('solid')->getStartColor()->setARGB('FFE6F2EF');
                $sheet->getStyle('L'.$r)->getFont()->setBold(true);
                $sheet->getRowDimension($r)->setRowHeight(24);
                $r++;
                $lastSection = $section;
            }
            $evidence = collect($finding->evidences)->map(fn ($e) => 'PDF hlm. '.$e['physical_page'].(! empty($e['printed_page']) ? ' (tercetak '.$e['printed_page'].')' : '').(! empty($e['quote']) ? ' — “'.$e['quote'].'”' : ''))->implode('; ');
            $text = '['.mb_strtoupper($finding->severity).' · '.$finding->rule_code.']'."\nDitemukan: ".$finding->observed."\nSeharusnya: ".$finding->expected."\nRevisi: ".$finding->recommendation."\nBukti: ".$evidence."\nStatus: ".$finding->review_status.($finding->review_note ? "\nCatatan pemeriksa: ".$finding->review_note : '');
            // Split long evidence across rows so Excel does not clip text at its 409pt limit.
            $chunks = mb_str_split($text, 1600);
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
            $sheet->mergeCells('L7:M7');
            $sheet->setCellValueExplicit('L7', 'Tidak ada temuan tervalidasi yang aktif. Semua halaman selesai diperiksa; keputusan akhir tetap melalui peninjauan.', DataType::TYPE_STRING);
            $sheet->getRowDimension(7)->setRowHeight(48);
            $r = 8;
        }
        $last = $r - 1;
        $sheet->getStyle('B7:M'.$last)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FF444444');
        $sheet->getStyle('L7:M'.$last)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getColumnDimension('M')->setWidth(120);
        $sheet->getColumnDimension('L')->setWidth(22);
        $sheet->getPageSetup()->setPrintArea('B3:M'.$last)->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(3, 4);
        $book->getProperties()->setTitle($pub->title)->setDescription('Aturan '.$pub->rule_version.'; revisi '.$pub->revision.'; seluruh '.$pub->page_count.' halaman diperiksa.');
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

        return ['validated' => true, 'last_row' => $last, 'finding_count' => $findings->count(), 'checks' => ['blank_outline', 'continuous_borders', 'no_formulas', 'reopened_xlsx'], 'sha256' => hash_file('sha256',$disk->path($path))];
    }
}
