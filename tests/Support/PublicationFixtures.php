<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Http\UploadedFile;

trait PublicationFixtures
{
    protected function pdfFile(int $count = 3): UploadedFile
    {
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>'];
        $kids = [];
        for ($page = 1; $page <= $count; $page++) {
            $id = 3 + ($page - 1) * 2;
            $kids[] = "$id 0 R";
            $objects[$id] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 '.(3 + $count * 2).' 0 R >> >> /Contents '.($id + 1).' 0 R >>';
            $text = "BT /F1 18 Tf 60 750 Td (PUBLIKASI TEST HALAMAN $page) Tj ET";
            $objects[$id + 1] = '<< /Length '.strlen($text)." >>\nstream\n$text\nendstream";
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids)."] /Count $count >>";
        $objects[3 + $count * 2] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $size = count($objects) + 1;
        $pdf .= "xref\n0 $size\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size $size /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";

        return UploadedFile::fake()->createWithContent('publikasi.pdf', $pdf);
    }

    protected function pageResult(int $page = 1, array $overrides = []): array
    {
        return array_replace([
            'physical_page' => $page, 'page_type' => 'narrative', 'readability' => 'readable',
            'printed_page' => (string) $page, 'text' => 'PUBLIKASI TEST HALAMAN '.$page,
            'inventory' => ['headings' => ['PUBLIKASI TEST'], 'tables' => [], 'figures' => [], 'footer' => '', 'header' => ''],
            'identity' => [], 'contents' => [], 'findings' => [],
        ], $overrides);
    }
}
