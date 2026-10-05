<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AiProviderConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

class AuditHealthCheck extends Command
{
    protected $signature = 'audit:health';

    protected $description = 'Periksa kesiapan database, Poppler, disk, antrean, dan koneksi vision.';

    public function handle(): int
    {
        DB::connection()->getPdo();
        $rows = [];
        $healthy = true;
        foreach (['pdfinfo', 'pdftoppm'] as $binary) {
            $p = new Process([config('audit.'.$binary), '-v']);
            $p->setTimeout(10);
            $p->run();
            $okay = $p->isSuccessful();
            $rows[] = [$binary, $okay ? 'tersedia' : 'tidak tersedia'];
            $healthy = $healthy && $okay;
        }
        $free = (int) (disk_free_space(storage_path()) / 1048576);
        $rows[] = ['Disk tersedia', $free.' MB'];
        $healthy = $healthy && $free > 1024;
        $rows[] = ['Driver antrean', config('queue.default')];
        $rows[] = ['Job database menunggu', DB::table('jobs')->count()];
        $rows[] = ['Job gagal', DB::table('failed_jobs')->count()];
        $last = DB::table('audit_events')->where('action', 'worker.heartbeat')->latest()->first();
        $alive = $last && strtotime($last->created_at) > time() - 180;
        $rows[] = ['Heartbeat worker', $alive ? 'aktif' : 'belum ada / terlambat'];
        $rows[] = ['Koneksi vision', AiProviderConfig::latest('id')->first()?->vision_tested_at ? 'lulus uji' : 'belum lulus'];
        $this->table(['Pemeriksaan', 'Hasil'], $rows);

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
