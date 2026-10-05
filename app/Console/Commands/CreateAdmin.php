<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'audit:create-admin {--email=} {--name=}';

    protected $description = 'Membuat akun admin awal; password diminta secara tersembunyi.';

    public function handle(): int
    {
        $data = ['email' => $this->option('email') ?: $this->ask('Email admin'), 'name' => $this->option('name') ?: $this->ask('Nama admin'), 'password' => $this->secret('Password (minimal 12 karakter, huruf besar-kecil dan angka)')];
        $v = Validator::make($data, ['email' => 'required|email|unique:users,email', 'name' => 'required|string|max:255', 'password' => ['required', Password::min(12)->mixedCase()->numbers()]]);
        if ($v->fails()) {
            $this->error($v->errors()->first());

            return self::FAILURE;
        }
        User::create([...$data, 'role' => 'admin', 'is_active' => true]);
        $this->info('Admin berhasil dibuat. Password tidak disimpan dalam log.');

        return self::SUCCESS;
    }
}
