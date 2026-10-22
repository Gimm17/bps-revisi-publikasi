<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Audit\NineRouterClient;
use App\Http\Requests\AiConfigRequest;
use App\Http\Requests\EmployeeRequest;
use App\Jobs\TestAiAgent;
use App\Models\AiAgent;
use App\Models\AiProviderConfig;
use App\Models\AiUsageRecord;
use App\Models\AuditEvent;
use App\Models\ReferenceProviderConfig;
use App\Models\User;
use App\Services\AiTelemetryService;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
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

        $existingAgents = AiAgent::orderBy('slot')->get()->keyBy('slot');
        $agent1 = $existingAgents->get(1);

        // Auto-seed Slot 6 (Agent Cadangan) if Agent 1 exists and Slot 6 is not created yet
        if (! $existingAgents->has(6) && $agent1 && ! empty($agent1->api_key)) {
            try {
                $backupHash = AiAgent::computeConfigHash($agent1->base_url, 'claude-sonnet-4-6', $agent1->api_key);
                $backup = AiAgent::create([
                    'slot' => 6,
                    'name' => 'Agent Cadangan (Standby)',
                    'base_url' => $agent1->base_url,
                    'api_key' => $agent1->api_key,
                    'model' => 'claude-sonnet-4-6',
                    'enabled' => true,
                    'max_concurrency' => 1,
                    'config_hash' => $backupHash,
                    'status' => 'ready',
                    'vision_tested_at' => now(),
                    'test_message' => 'Siap bertindak sebagai agen cadangan saat ada filter pembatasan.',
                    'created_by' => $agent1->created_by,
                ]);
                $existingAgents->put(6, $backup);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Failed auto-initializing backup agent: '.$e->getMessage());
            }
        }

        $agentsList = [];
        for ($slot = 1; $slot <= 6; $slot++) {
            $agent = $existingAgents->get($slot);
            $defaultName = ($slot === 6) ? 'Agent Cadangan (Standby)' : 'Agent '.$slot;
            $defaultModel = ($slot === 6) ? 'claude-sonnet-4-6' : 'gemini-2.5-flash';
            $defaultBaseUrl = ($slot === 6) ? ($agent1?->base_url ?? 'https://api.9router.com/v1') : ($agent?->base_url ?? 'https://api.9router.com/v1');
            $agentsList[] = [
                'id' => $agent?->id,
                'slot' => $slot,
                'name' => $agent?->name ?? $defaultName,
                'base_url' => $agent?->base_url ?? $defaultBaseUrl,
                'model' => $agent?->model ?? $defaultModel,
                'enabled' => $agent ? (bool) $agent->enabled : ($slot === 1 || $slot === 6),
                'status' => $agent?->status ?? 'untested',
                'test_message' => $agent?->test_message,
                'vision_tested_at' => $agent?->vision_tested_at?->toIso8601String(),
                'has_api_key' => ! empty($agent?->api_key),
                'max_concurrency' => $agent?->max_concurrency ?? 1,
                'is_backup' => ($slot === 6),
                'updated_at' => $agent?->updated_at?->toIso8601String(),
            ];
        }

        return Inertia::render('Settings', [
            'users' => User::orderBy('name')->get(['id', 'name', 'email', 'role', 'is_active']),
            'ai_agents' => $agentsList,
            'ai' => AiProviderConfig::latest('id')->first()?->only('id', 'base_url', 'model', 'tested_at', 'vision_tested_at', 'updated_at'),
            'reference_provider' => ReferenceProviderConfig::latest('id')->first()?->only('id', 'provider', 'endpoint', 'is_ready', 'tested_at', 'updated_at'),
            'events' => AuditEvent::latest()->limit(100)->get(),
            'telemetry' => app(AiTelemetryService::class)->getReport('7d'),
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

    public function saveAiAgent(Request $request, int $slot, NineRouterClient $client): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        if ($slot < 1 || $slot > 6) {
            abort(404, 'Slot agen AI tidak valid.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'base_url' => ['required', 'url', 'max:500'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'model' => ['required', 'string', 'max:150'],
            'enabled' => ['required', 'boolean'],
            'max_concurrency' => ['nullable', 'integer', 'min:1', 'max:5'],
        ]);

        $validatedUrl = $client->validateUrl($data['base_url']);
        $existing = AiAgent::where('slot', $slot)->first();

        $apiKey = ! empty($data['api_key']) ? trim($data['api_key']) : $existing?->api_key;
        if (empty($apiKey)) {
            throw ValidationException::withMessages(['api_key' => 'API Key wajib diisi untuk Agen AI baru.']);
        }

        $newHash = AiAgent::computeConfigHash($validatedUrl, $data['model'], $apiKey);
        $configChanged = ! $existing || $existing->config_hash !== $newHash;

        $agent = AiAgent::updateOrCreate(
            ['slot' => $slot],
            [
                'name' => $data['name'],
                'base_url' => $validatedUrl,
                'api_key' => $apiKey,
                'model' => $data['model'],
                'enabled' => (bool) $data['enabled'],
                'max_concurrency' => $data['max_concurrency'] ?? 1,
                'config_hash' => $newHash,
                'status' => $configChanged ? 'untested' : $existing->status,
                'vision_tested_at' => $configChanged ? null : $existing->vision_tested_at,
                'test_message' => $configChanged ? 'Konfigurasi disimpan. Jalankan uji pembacaan gambar.' : $existing->test_message,
                'created_by' => $request->user()->id,
            ]
        );

        if ($slot === 1) {
            AiProviderConfig::create([
                'base_url' => $validatedUrl,
                'model' => $data['model'],
                'api_key' => $apiKey,
                'created_by' => $request->user()->id,
                'tested_at' => $agent->vision_tested_at,
                'vision_tested_at' => $agent->vision_tested_at,
            ]);
        }

        AuditLogger::record('ai_agent.saved', null, [
            'slot' => $slot,
            'name' => $agent->name,
            'model' => $agent->model,
            'enabled' => $agent->enabled,
        ]);

        return back()->with('success', "Konfigurasi {$agent->name} (Slot {$slot}) berhasil disimpan.");
    }

    public function testAiAgent(Request $request, AiAgent $agent): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $agent->update([
            'status' => 'testing',
            'test_message' => 'Sedang menguji koneksi dan pembacaan gambar...',
        ]);

        TestAiAgent::dispatch($agent->id);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Pengujian {$agent->name} sedang berlangsung.",
                'agent' => [
                    'id' => $agent->id,
                    'slot' => $agent->slot,
                    'status' => 'testing',
                ],
            ]);
        }

        return back()->with('success', "Pengujian {$agent->name} sedang berlangsung di antrean.");
    }

    public function testAllAiAgents(Request $request): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $agents = AiAgent::where('enabled', true)->get();
        if ($agents->isEmpty()) {
            throw ValidationException::withMessages(['agents' => 'Tidak ada Agen AI aktif untuk diuji. Aktifkan minimal 1 agen.']);
        }

        foreach ($agents as $agent) {
            $agent->update([
                'status' => 'testing',
                'test_message' => 'Sedang menguji koneksi dan pembacaan gambar...',
            ]);
            TestAiAgent::dispatch($agent->id);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Pengujian untuk {$agents->count()} agen sedang berjalan di antrean.",
                'count' => $agents->count(),
            ]);
        }

        return back()->with('success', "Pengujian untuk {$agents->count()} agen sedang berjalan di antrean.");
    }

    public function aiAgentsStatus(): JsonResponse
    {
        $agents = AiAgent::orderBy('slot')->get()->map(fn ($agent) => [
            'id' => $agent->id,
            'slot' => $agent->slot,
            'name' => $agent->name,
            'model' => $agent->model,
            'enabled' => (bool) $agent->enabled,
            'status' => $agent->status,
            'test_message' => $agent->test_message,
            'vision_tested_at' => $agent->vision_tested_at?->toIso8601String(),
        ]);

        return response()->json([
            'agents' => $agents,
            'any_testing' => $agents->contains('status', 'testing'),
            'ready_count' => $agents->where('status', 'ready')->where('enabled', true)->count(),
        ]);
    }

    public function copyAgent(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate([
            'from_slot' => ['required', 'integer', 'between:1,6'],
            'to_slot' => ['required', 'integer', 'between:1,6', 'different:from_slot'],
        ]);

        $source = AiAgent::where('slot', $data['from_slot'])->firstOrFail();
        if (empty($source->api_key)) {
            throw ValidationException::withMessages(['from_slot' => 'Agen sumber belum memiliki konfigurasi lengkap.']);
        }

        $targetName = ($data['to_slot'] === 6) ? 'Agent Cadangan (Standby)' : "Agent {$data['to_slot']}";
        $targetModel = ($data['to_slot'] === 6) ? 'claude-sonnet-4-6' : $source->model;
        $hash = AiAgent::computeConfigHash($source->base_url, $targetModel, $source->api_key);
        AiAgent::updateOrCreate(
            ['slot' => $data['to_slot']],
            [
                'name' => $targetName,
                'base_url' => $source->base_url,
                'api_key' => $source->api_key,
                'model' => $targetModel,
                'enabled' => true,
                'max_concurrency' => 1,
                'status' => 'untested',
                'vision_tested_at' => null,
                'test_message' => "Disalin dari Agent {$data['from_slot']}. Jalankan uji pembacaan gambar.",
                'config_hash' => $hash,
                'created_by' => $request->user()->id,
            ]
        );

        return back()->with('success', "Konfigurasi disalin dari Agent {$data['from_slot']} ke {$targetName}.");
    }

    public function aiTelemetry(Request $request, AiTelemetryService $telemetry): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $range = (string) $request->query('range', '7d');
        if (! in_array($range, ['24h', '3d', '7d', '30d', 'all'], true)) {
            $range = '7d';
        }

        return response()->json($telemetry->getReport($range));
    }
}
