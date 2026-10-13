<?php

declare(strict_types=1);

namespace App\Audit;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

class AiCapacity
{
    public function acquire(string $host): Lock
    {
        $remaining = (int) Cache::get($this->cooldownKey($host), 0) - now()->timestamp;
        if ($remaining > 0) {
            throw new AiRequestException('Penyedia AI sedang membatasi permintaan; menunggu sebelum mencoba lagi.', 'capacity', true, $remaining);
        }
        for ($slot = 0; $slot < max(1, min(4, (int) config('audit.ai_concurrency', 2))); $slot++) {
            $lock = Cache::lock('audit:ai:slot:'.$slot, 250);
            if ($lock->get()) {
                return $lock;
            }
        }
        throw new AiRequestException('Menunggu giliran pembacaan AI.', 'capacity', true, 10);
    }

    public function coolDown(string $host, int $seconds): void
    {
        Cache::lock($this->cooldownKey($host).':update', 5)->block(2, function () use ($host, $seconds): void {
            $until = max((int) Cache::get($this->cooldownKey($host), 0), now()->timestamp + max(1, min(600, $seconds)));
            Cache::put($this->cooldownKey($host), $until, max(1, $until - now()->timestamp));
        });
    }

    private function cooldownKey(string $host): string
    {
        return 'audit:ai:cooldown:'.hash('sha256', strtolower($host));
    }
}
