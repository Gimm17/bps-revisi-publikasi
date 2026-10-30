<?php

declare(strict_types=1);

namespace App\Audit;

use App\Audit\Contracts\AiEndpoint;
use App\Models\AiAgent;
use App\Models\Publication;
use Illuminate\Support\Facades\DB;

class AgentPoolSelector
{
    public function __construct(
        private readonly AiCapacity $capacity
    ) {}

    /**
     * Select a ready AI agent for a page audit.
     */
    public function selectAgent(Publication $publication, ?int $excludeAgentId = null): ?AiEndpoint
    {
        // Prefer global live ready agents so newly enabled/updated slots (1..15) join active audits immediately
        $agents = AiAgent::ready()
            ->where('slot', '<=', AiAgent::MAX_PRIMARY_SLOTS)
            ->when($excludeAgentId !== null, fn ($q) => $q->where('id', '!=', $excludeAgentId))
            ->orderBy('slot')
            ->get();

        if ($agents->isEmpty()) {
            $agents = $publication->aiAgents()
                ->where('enabled', true)
                ->where('status', 'ready')
                ->whereNotNull('vision_tested_at')
                ->where('slot', '<=', AiAgent::MAX_PRIMARY_SLOTS)
                ->when($excludeAgentId !== null, fn ($q) => $q->where('ai_agents.id', '!=', $excludeAgentId))
                ->get();

            if ($agents->isEmpty()) {
                if ($publication->aiConfig?->vision_tested_at && $excludeAgentId === null) {
                    return $publication->aiConfig;
                }

                return null;
            }
        }

        // Filter out agents currently in cooldown
        $available = $agents->filter(fn (AiAgent $agent) => ! $this->capacity->isCoolingDown($agent));

        if ($available->isEmpty()) {
            // Find minimum cooldown remaining across candidates
            $minCooldown = $agents->map(fn (AiAgent $agent) => $this->capacity->remainingCooldown($agent))->min() ?? 15;
            throw new AiRequestException('Semua agen AI sedang dalam jeda (cooldown). Mencoba lagi...', 'capacity', true, max(5, $minCooldown));
        }

        // Pick an IDLE agent first (not currently holding its concurrency lock or analyzing),
        // then balance by fewest pages processed in this publication
        $usageCounts = DB::table('publication_pages')
            ->where('publication_id', $publication->id)
            ->whereNotNull('ai_agent_id')
            ->select('ai_agent_id', DB::raw('count(*) as count'))
            ->groupBy('ai_agent_id')
            ->pluck('count', 'ai_agent_id');

        $busyCounts = DB::table('publication_pages')
            ->where('status', 'analyzing')
            ->whereNotNull('ai_agent_id')
            ->select('ai_agent_id', DB::raw('count(*) as count'))
            ->groupBy('ai_agent_id')
            ->pluck('count', 'ai_agent_id');

        return $available->sortBy(function (AiAgent $agent) use ($usageCounts, $busyCounts): array {
            $isAnalyzingBusy = $busyCounts->get($agent->id, 0) >= max(1, (int) $agent->max_concurrency);
            $isLockBusy = $isAnalyzingBusy || $this->capacity->isBusy($agent);

            return [
                $isLockBusy ? 1 : 0,
                (int) $usageCounts->get($agent->id, 0),
                (int) $agent->slot,
            ];
        })->first();
    }
}
