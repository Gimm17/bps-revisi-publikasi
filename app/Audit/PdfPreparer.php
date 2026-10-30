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
            if (preg_match('/incorrect password/i', $process->getErrorOutput())) {
                throw new RuntimeException('PDF memerlukan password untuk dibuka. Unggah PDF yang dapat dibuka tanpa password.');
            }
            throw new RuntimeException('PDF rusak atau tidak dapat dibaca.');
        }
        $output = $process->getOutput();
        $encrypted = (bool) preg_match('/Encrypted:\s+yes/i', $output);
        preg_match('/Pages:\s+(\d+)/', $output, $match);
        $count = (int) ($match[1] ?? 0);
        if ($count < 1 || $count > config('audit.max_pages')) {
            throw new RuntimeException('Jumlah halaman PDF tidak didukung (1–'.config('audit.max_pages').').');
        }
        preg_match('/Page size:\s+([\d.]+) x ([\d.]+)/', $output, $size);

        return ['page_count' => $count, 'width_pt' => (float) ($size[1] ?? 0), 'height_pt' => (float) ($size[2] ?? 0), 'encrypted' => $encrypted, 'sha256' => hash_file('sha256', $path)];
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

    public function extractText(string $pdfPath, int $page, bool $layout = true): string
    {
        if ($page < 1) {
            throw new RuntimeException('Nomor halaman tidak valid.');
        }

        // 1. Coba gunakan pdftotext binary jika tersedia
        $pdftotext = config('audit.pdftotext', 'pdftotext');
        if (! empty($pdftotext)) {
            $cmd = [
                $pdftotext,
                '-f', (string) $page,
                '-l', (string) $page,
                '-enc', 'UTF-8',
            ];
            if ($layout) {
                $cmd[] = '-layout';
            }
            $cmd[] = $pdfPath;
            $cmd[] = '-';

            try {
                $process = new Process($cmd);
                $process->setTimeout(30);
                $process->run();

                if ($process->isSuccessful() && trim($process->getOutput()) !== '') {
                    return $process->getOutput();
                }
            } catch (\Throwable $e) {
                // Lanjut ke fallback Ghostscript / Smalot
            }
        }

        // 2. Fallback Ghostscript (txtwrite) — mendukung PDF BPS yang terproteksi permission (Secured PDF)
        $gsBinary = is_executable('/usr/bin/gs') ? '/usr/bin/gs' : 'gs';
        try {
            $gsProcess = new Process([
                $gsBinary,
                '-q',
                '-dNOPAUSE',
                '-dBATCH',
                '-sDEVICE=txtwrite',
                '-dFirstPage='.$page,
                '-dLastPage='.$page,
                '-sOutputFile=-',
                $pdfPath,
            ]);
            $gsProcess->setTimeout(30);
            $gsProcess->run();

            if ($gsProcess->isSuccessful() && trim($gsProcess->getOutput()) !== '') {
                return $gsProcess->getOutput();
            }
        } catch (\Throwable $e) {
            // Lanjut ke fallback Smalot Parser
        }

        // 3. Fallback pure-PHP Smalot PDF Parser
        if (class_exists(\Smalot\PdfParser\Parser::class)) {
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $document = $parser->parseFile($pdfPath);
                $pages = $document->getPages();
                if (isset($pages[$page - 1])) {
                    return (string) $pages[$page - 1]->getText();
                }
            } catch (\Throwable $e) {
                // Abaikan kesalahan parser fallback
            }
        }

        return '';
    }
}
