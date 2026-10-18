<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Audit\NineRouterClient;
use App\Http\Requests\AiConfigRequest;
use App\Http\Requests\EmployeeRequest;
use App\Models\AiProviderConfig;
use App\Models\AiUsageRecord;
use App\Models\AuditEvent;
use App\Models\ReferenceProviderConfig;
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
            'reference_provider' => ReferenceProviderConfig::latest('id')->first()?->only('id', 'provider', 'endpoint', 'is_ready', 'tested_at', 'updated_at'),
            'events' => AuditEvent::latest()->limit(100)->get(),
            'health' => ['queue' => DB::table('jobs')->count(), 'failed' => DB::table('failed_jobs')->count(), 'usage' => AiUsageRecord::count(), 'driver' => config('queue.default'), 'disk_free_mb' => (int) (disk_free_space(storage_path()) / 1048576)],
        ]);
    }

    public function saveReferenceProvider(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate([
            'provider' => ['required', 'string', 'in:browserless,brave'],
            'endpoint' => ['required', 'url', 'max:500'],
            'api_key' => ['nullable', 'string', 'max:500'],
        ]);
        $prev = ReferenceProviderConfig::where('provider', $data['provider'])->latest('id')->first();
        $apiKey = $data['api_key'] ?? $prev?->api_key;
        if (! $apiKey) {
            throw ValidationException::withMessages(['api_key' => 'API key penyedia wajib diisi pada konfigurasi awal.']);
        }
        ReferenceProviderConfig::create([
            'provider' => $data['provider'],
            'endpoint' => $data['endpoint'],
            'api_key' => $apiKey,
            'created_by' => $request->user()->id,
            'is_ready' => false,
        ]);
        AuditLogger::record('reference_provider.updated', null, ['provider' => $data['provider']]);

        return back()->with('success', 'Konfigurasi penyedia rujukan disimpan.');
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
        $previous = AiProviderConfig::latest('id')->first();
        if (empty($data['api_key']) && $previous && parse_url($data['base_url'], PHP_URL_HOST) !== parse_url($previous->base_url, PHP_URL_HOST)) {
            throw ValidationException::withMessages(['api_key' => 'Isi API key penyedia yang baru saat mengganti host koneksi.']);
        }
        $data['api_key'] = $data['api_key'] ?? $previous?->api_key;
        if (! $data['api_key']) {
            throw ValidationException::withMessages(['api_key' => 'API key penyedia AI wajib diisi pada koneksi pertama.']);
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
            throw ValidationException::withMessages(['ai' => 'Uji gagal. Periksa alamat, API key, model vision, dan kuota penyedia AI.']);
        }

        return back()->with('success', 'Koneksi dan pembacaan gambar berhasil diuji.');
    }
}
