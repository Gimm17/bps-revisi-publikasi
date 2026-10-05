<?php

declare(strict_types=1);

namespace App\Audit;

use RuntimeException;
use Symfony\Component\Process\Process;

class PdfPreparer
{
    public function inspect(string $path): array
    {
        if (! is_file($path) || ! str_starts_with(file_get_contents($path, false, null, 0, 5), '%PDF-')) {
            throw new RuntimeException('Berkas bukan PDF yang valid.');
        }
        $process = new Process([config('audit.pdfinfo'), $path]);
        $process->setTimeout(30);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('PDF rusak atau tidak dapat dibaca.');
        }
        $output = $process->getOutput();
        if (preg_match('/Encrypted:\s+yes/i', $output)) {
            throw new RuntimeException('PDF terenkripsi. Unggah PDF tanpa password.');
        }
        preg_match('/Pages:\s+(\d+)/', $output, $match);
        $count = (int) ($match[1] ?? 0);
        if ($count < 1 || $count > config('audit.max_pages')) {
            throw new RuntimeException('Jumlah halaman PDF tidak didukung (1–'.config('audit.max_pages').').');
        }
        preg_match('/Page size:\s+([\d.]+) x ([\d.]+)/', $output, $size);

        return ['page_count' => $count, 'width_pt' => (float) ($size[1] ?? 0), 'height_pt' => (float) ($size[2] ?? 0), 'encrypted' => false, 'sha256' => hash_file('sha256', $path)];
    }

    public function render(string $pdfPath, int $page, string $outputPath, int $dpi): void
    {
        if ($page < 1 || $dpi < 60 || $dpi > 300) {
            throw new RuntimeException('Halaman atau resolusi tidak valid.');
        }
        if (! is_dir(dirname($outputPath))) {
            mkdir(dirname($outputPath), 0700, true);
        }
        $prefix = substr($outputPath, 0, -4);
        $process = new Process([config('audit.pdftoppm'), '-f', (string) $page, '-l', (string) $page, '-singlefile', '-jpeg', '-r', (string) $dpi, $pdfPath, $prefix]);
        $process->setTimeout(90);
        $process->run();
        if (! $process->isSuccessful() || ! is_file($outputPath) || ! getimagesize($outputPath)) {
            throw new RuntimeException('Gambar halaman '.$page.' gagal dibuat.');
        }
    }
}
