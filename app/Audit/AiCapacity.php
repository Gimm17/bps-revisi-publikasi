<?php

declare(strict_types=1);

namespace App\Audit;

use App\Audit\Contracts\AiEndpoint;
use App\Models\AiAgent;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

class AiCapacity
{
    /**
     * Acquire concurrency lock for given endpoint/agent.
     */
    public function acquire(AiEndpoint|string $endpoint): Lock
    {
        $remaining = $this->remainingCooldown($endpoint);
        if ($remaining > 0) {
            throw new AiRequestException('Penyedia AI sedang membatasi permintaan; menunggu sebelum mencoba lagi.', 'capacity', true, $remaining);
        }

        $globalLimit = max(AiAgent::MAX_PRIMARY_SLOTS, (int) config('audit.ai_concurrency', 15));
        $globalLock = null;

        for ($slot = 0; $slot < $globalLimit; $slot++) {
            $lock = Cache::lock('audit:ai:global:slot:'.$slot, 250);
            if ($lock->get()) {
                $globalLock = $lock;
                break;
            }
        }

        if (! $globalLock) {
            throw new AiRequestException('Menunggu giliran pembacaan AI (slot global penuh).', 'capacity', true, 10);
        }

        // If endpoint is a specific AiAgent with max_concurrency, also acquire agent slot
        if ($endpoint instanceof AiAgent) {
            $agentLimit = max(1, $endpoint->max_concurrency);
            $agentLock = null;

            for ($slot = 0; $slot < $agentLimit; $slot++) {
                $lock = Cache::lock('audit:ai:agent:'.$endpoint->id.':slot:'.$slot, 250);
                if ($lock->get()) {
                    $agentLock = $lock;
                    break;
                }
            }

            if (! $agentLock) {
                $globalLock->release();
                throw new AiRequestException('Menunggu giliran untuk '.$endpoint->name.' (kapasitas agent terpakai).', 'capacity', true, 5);
            }

            return new class($globalLock, $agentLock) implements Lock
            {
                public function __construct(
                    private readonly Lock $first,
                    private readonly Lock $second
                ) {}

                public function get($callback = null)
                {
                    return true;
                }

                public function block($seconds, $callback = null)
                {
                    return true;
                }

                public function release(): bool
                {
                    $r1 = $this->first->release();
                    $r2 = $this->second->release();

                    return $r1 || $r2;
                }

                public function owner(): string
                {
                    return $this->first->owner();
                }

                public function forceRelease(): void
                {
                    $this->first->forceRelease();
                    $this->second->forceRelease();
                }
            };
        }

        return $globalLock;
    }

    public function coolDown(AiEndpoint|string $endpoint, int $seconds): void
    {
        $key = $this->cooldownKey($endpoint);
        Cache::lock($key.':update', 5)->block(2, function () use ($key, $seconds): void {
            $until = max((int) Cache::get($key, 0), now()->timestamp + max(1, min(600, $seconds)));
            Cache::put($key, $until, max(1, $until - now()->timestamp));
        });
    }

    public function isCoolingDown(AiEndpoint|string $endpoint): bool
    {
        return $this->remainingCooldown($endpoint) > 0;
    }

    public function remainingCooldown(AiEndpoint|string $endpoint): int
    {
        $key = $this->cooldownKey($endpoint);

        return max(0, (int) Cache::get($key, 0) - now()->timestamp);
    }

    private function cooldownKey(AiEndpoint|string $endpoint): string
    {
        if ($endpoint instanceof AiEndpoint) {
            return 'audit:ai:cooldown:'.$endpoint->capacityKey();
        }

        return 'audit:ai:cooldown:'.hash('sha256', strtolower($endpoint));
    }
}
