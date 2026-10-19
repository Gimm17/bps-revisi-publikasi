<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Audit\NineRouterClient;
use App\Models\AiAgent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class TestAiAgent implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public int $agentId
    ) {}

    public function handle(NineRouterClient $client): void
    {
        $agent = AiAgent::find($this->agentId);
        if (! $agent || ! $agent->enabled) {
            return;
        }

        $agent->update([
            'status' => 'testing',
            'test_message' => null,
        ]);

        try {
            $client->testVision($agent);

            $hash = AiAgent::computeConfigHash($agent->base_url, $agent->model, $agent->api_key);
            $agent->update([
                'status' => 'ready',
                'vision_tested_at' => now(),
                'config_hash' => $hash,
                'test_message' => 'Lulus uji pembacaan gambar.',
            ]);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            // Sanitize potential token/key leakage in error message
            $safeMessage = preg_match('/^Penyedia AI mengembalikan status HTTP \d{3}\./', $msg) || in_array($msg, [
                'Uji pembacaan gambar gagal. Pastikan model dan rute meneruskan gambar.',
                'Model belum berhasil membaca halaman PDF rujukan.',
                'Koneksi penyedia AI gagal atau melewati batas waktu.',
                'Host penyedia AI tidak dapat dihubungi.',
                'Alamat host penyedia AI tidak diizinkan.',
            ], true) ? $msg : 'Uji koneksi gagal. Periksa base URL, API key, dan kesesuaian model vision.';

            $agent->update([
                'status' => 'failed',
                'vision_tested_at' => null,
                'test_message' => $safeMessage,
            ]);
        }
    }
}
