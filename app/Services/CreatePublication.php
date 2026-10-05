<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\PdfPreparer;
use App\Audit\RuleRegistry;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

class CreatePublication
{
    public function execute(User $user, UploadedFile $pdf, ?UploadedFile $template, array $metadata): Publication
    {
        try {
            $manifest = app(PdfPreparer::class)->inspect($pdf->getPathname());
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['pdf' => $e->getMessage()]);
        }
        $templatePath = $template?->getPathname() ?? resource_path('templates/base.xlsx');
        try {
            $zip = new \ZipArchive;
            if ($zip->open($templatePath) !== true) {
                throw new \RuntimeException('Bukan arsip XLSX.');
            }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                $total += $entry['size'];
                if ($total > 40 * 1024 * 1024 || $zip->numFiles > 2500 || str_contains(strtolower($entry['name']), 'vbaproject') || str_contains($entry['name'], '../')) {
                    throw new \RuntimeException('Template terlalu besar atau mengandung macro.');
                }
            }
            $zip->close();
            $workbook = IOFactory::load($templatePath);
            $sheet = $workbook->getActiveSheet();
            if (Coordinate::columnIndexFromString($sheet->getHighestColumn()) < 13 || $sheet->getHighestRow() < 7) {
                throw new \RuntimeException('Template harus mempunyai struktur kolom B–M dan header hingga baris 6.');
            }
            $workbook->disconnectWorksheets();
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['template' => 'Template XLSX tidak valid atau tidak sesuai format pemeriksaan B–M.']);
        }
        $id = (string) Str::uuid();
        $disk = Storage::disk('local');
        $pdfPath = $pdf->storeAs('publications/'.$id, 'original.pdf', 'local');
        $xlsxPath = 'publications/'.$id.'/template.xlsx';
        $disk->put($xlsxPath, file_get_contents($templatePath));
        $disk->put('publications/'.$id.'/rules.md', app(RuleRegistry::class)->guidance());
        $disk->put('publications/'.$id.'/prompt.md', file_get_contents(resource_path('prompts/page-audit-v1.md')));
        try {
            return DB::transaction(function () use ($id, $user, $metadata, $manifest, $pdf, $template, $pdfPath, $xlsxPath, $disk): Publication {
                $pub = new Publication([...$metadata, 'owner_id' => $user->id, 'pdf_path' => $pdfPath, 'pdf_name' => $pdf->getClientOriginalName(), 'pdf_checksum' => $manifest['sha256'], 'template_path' => $xlsxPath, 'template_name' => $template?->getClientOriginalName() ?? 'Template pemeriksaan standar.xlsx', 'template_checksum' => hash_file('sha256', $disk->path($xlsxPath)), 'page_count' => $manifest['page_count'], 'manifest' => $manifest, 'rule_version' => config('audit.rule_version')]);
                $pub->id = $id;
                $pub->save();
                for ($page = 1; $page <= $manifest['page_count']; $page++) {
                    $pub->pages()->create(['physical_page' => $page]);
                }
                AuditLogger::record('publication.uploaded', $pub, ['page_count' => $pub->page_count], $user);

                return $pub;
            });
        } catch (\Throwable $e) {
            $disk->delete([$pdfPath, $xlsxPath]);
            throw $e;
        }
    }
}
