<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LocalAccess extends Command
{
    protected $signature = 'audit:local-access';

    protected $description = 'Buat akses lokal pertama dengan password acak (tidak tersedia di produksi).';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Perintah hanya untuk lingkungan lokal.');

            return self::FAILURE;
        }
        if (User::where('role', 'admin')->exists()) {
            $this->info('Admin sudah ada. Akun tidak diubah.');

            return self::SUCCESS;
        }
        $password = 'Ruang9!'.Str::random(32);
        User::create(['name' => 'Admin Publikasi', 'email' => 'admin@bps.local', 'password' => $password, 'role' => 'admin', 'is_active' => true]);
        Storage::disk('local')->put('AKSES-LOKAL.md', "# Akses lokal Ruang Periksa\n\nAlamat: http://127.0.0.1:8000\n\nEmail: admin@bps.local\n\nPassword awal: ".$password."\n\nAkun ini hanya untuk komputer lokal. Ganti password melalui Pengaturan > Pegawai. Jangan unggah file ini atau database lokal ke hosting.\n");
        $this->info('Akses lokal dibuat. Lihat storage/app/private/AKSES-LOKAL.md; password tidak ditampilkan dalam log.');

        return self::SUCCESS;
    }
}
