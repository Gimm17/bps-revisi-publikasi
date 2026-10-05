<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Audit\NineRouterClient;
use App\Http\Requests\AiConfigRequest;
use App\Http\Requests\EmployeeRequest;
use App\Models\AiProviderConfig;
use App\Models\AiUsageRecord;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->isAdmin(), 403);

        return Inertia::render('Settings', [
            'users' => User::orderBy('name')->get(['id', 'name', 'email', 'role', 'is_active']),
            'ai' => AiProviderConfig::latest('id')->first()?->only('id', 'base_url', 'model', 'tested_at', 'vision_tested_at', 'updated_at'),
            'events' => AuditEvent::latest()->limit(100)->get(),
            'health' => ['queue' => DB::table('jobs')->count(), 'failed' => DB::table('failed_jobs')->count(), 'usage' => AiUsageRecord::count(), 'driver' => config('queue.default'), 'disk_free_mb' => (int) (disk_free_space(storage_path()) / 1048576)],
        ]);
    }

    public function createUser(EmployeeRequest $request): RedirectResponse
    {
        $user = User::create([...$request->validated(), 'is_active' => true]);
        AuditLogger::record('user.created', null, ['user_id' => $user->id, 'role' => $user->role]);

        return back()->with('success', 'Akun pegawai berhasil dibuat.');
    }

    public function updateUser(EmployeeRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();
        if ($user->id === $request->user()->id && (! (bool) ($data['is_active'] ?? true) || $data['role'] !== 'admin')) {
            throw ValidationException::withMessages(['role' => 'Admin tidak dapat menonaktifkan atau menurunkan peran akun sendiri.']);
        }
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $user->update($data);
        AuditLogger::record('user.updated', null, ['user_id' => $user->id, 'role' => $user->role, 'is_active' => $user->is_active]);

        return back()->with('success', 'Akun pegawai diperbarui.');
    }

    public function saveAi(AiConfigRequest $request, NineRouterClient $client): RedirectResponse
    {
        $data = $request->validated();
        $data['base_url'] = $client->validateUrl($data['base_url']);
        $data['api_key'] = $data['api_key'] ?? AiProviderConfig::latest('id')->first()?->api_key;
        if (! $data['api_key']) {
            throw ValidationException::withMessages(['api_key' => 'API key 9Router wajib diisi pada koneksi pertama.']);
        }
        AiProviderConfig::create([...$data, 'created_by' => $request->user()->id]);
        AuditLogger::record('ai.configuration_updated', null, ['base_url' => $data['base_url'], 'model' => $data['model']]);

        return back()->with('success', 'Koneksi disimpan. Jalankan uji pembacaan gambar sebelum digunakan.');
    }

    public function testAi(Request $request, NineRouterClient $client): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $config = AiProviderConfig::latest('id')->first();
        if (! $config) {
            throw ValidationException::withMessages(['ai' => 'Simpan konfigurasi terlebih dahulu.']);
        }
        try {
            $client->testVision($config);
            $config->update(['tested_at' => now(), 'vision_tested_at' => now()]);
            AuditLogger::record('ai.vision_test_passed', null, ['config_id' => $config->id]);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['ai' => 'Uji gagal. Periksa alamat, API key, model vision, dan kuota 9Router.']);
        }

        return back()->with('success', 'Koneksi dan pembacaan gambar berhasil diuji.');
    }
}
