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
        $agents = $publication->aiAgents()
            ->where('enabled', true)
            ->where('status', 'ready')
            ->whereNotNull('vision_tested_at')
            ->when($excludeAgentId !== null, fn ($q) => $q->where('ai_agents.id', '!=', $excludeAgentId))
            ->get();

        if ($agents->isEmpty()) {
            // If no multi-agents attached, check if there are global ready agents
            $globalReady = AiAgent::ready()
                ->when($excludeAgentId !== null, fn ($q) => $q->where('id', '!=', $excludeAgentId))
                ->orderBy('slot')
                ->get();

            if ($globalReady->isNotEmpty()) {
                $agents = $globalReady;
            } elseif ($publication->aiConfig?->vision_tested_at && $excludeAgentId === null) {
                // Fallback to legacy single config if applicable
                return $publication->aiConfig;
            } else {
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

        // Pick the agent that has processed the fewest pages in this publication for balanced distribution
        $usageCounts = DB::table('publication_pages')
            ->where('publication_id', $publication->id)
            ->whereNotNull('ai_agent_id')
            ->select('ai_agent_id', DB::raw('count(*) as count'))
            ->groupBy('ai_agent_id')
            ->pluck('count', 'ai_agent_id');

        return $available->sortBy(fn (AiAgent $agent) => $usageCounts->get($agent->id, 0))->first();
    }
}
