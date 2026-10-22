<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AiAgent;
use App\Models\AiUsageRecord;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AiTelemetryService
{
    // Model pricing per 1M tokens (USD)
    private const MODEL_PRICING = [
        'gemini-3.8-flash' => ['input' => 0.075, 'output' => 0.30],
        'gemini-2.5-flash' => ['input' => 0.075, 'output' => 0.30],
        'gemini-1.5-flash' => ['input' => 0.075, 'output' => 0.30],
        'gemma-4-31b-it' => ['input' => 0.10, 'output' => 0.40],
        'claude-sonnet-4-6' => ['input' => 3.00, 'output' => 15.00],
        'claude-3-5-sonnet' => ['input' => 3.00, 'output' => 15.00],
        'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
        'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
        'default' => ['input' => 0.15, 'output' => 0.60],
    ];

    private const USD_TO_IDR = 16000;

    /**
     * Get complete telemetry report for the specified range.
     * Range options: '24h', '3d', '7d', '30d', 'all'
     */
    public function getReport(string $range = '7d'): array
    {
        $startDate = match ($range) {
            '24h', '1d', 'day' => now()->subHours(24),
            '3d' => now()->subDays(3)->startOfDay(),
            '7d', 'week' => now()->subDays(7)->startOfDay(),
            '30d', 'month' => now()->subDays(30)->startOfDay(),
            default => null, // 'all'
        };

        $query = AiUsageRecord::query()->with(['agent:id,slot,name,model', 'page:id,physical_page', 'publication:id,title']);
        if ($startDate !== null) {
            $query->where('created_at', '>=', $startDate);
        }

        $records = $query->orderBy('id', 'desc')->get();

        $totalRequests = $records->count();
        $successRequests = 0;
        $failedRequests = 0;
        $totalPromptTokens = 0;
        $totalCompletionTokens = 0;
        $totalTokens = 0;
        $totalDurationMs = 0;
        $totalCostUsd = 0.0;

        foreach ($records as $r) {
            $isFailed = str_starts_with((string) $r->status, 'failed');
            if ($isFailed) {
                $failedRequests++;
            } else {
                $successRequests++;
            }

            $u = $r->usage ?? [];
            $prompt = (int) ($u['prompt_tokens'] ?? 0);
            $completion = (int) ($u['completion_tokens'] ?? 0);
            $total = (int) ($u['total_tokens'] ?? ($prompt + $completion));

            $totalPromptTokens += $prompt;
            $totalCompletionTokens += $completion;
            $totalTokens += $total;
            $totalDurationMs += (int) $r->duration_ms;

            $rates = $this->getModelRates((string) $r->model);
            $recordCost = ($prompt * ($rates['input'] / 1_000_000)) + ($completion * ($rates['output'] / 1_000_000));
            $totalCostUsd += $recordCost;
        }

        $avgTokensPerReq = $totalRequests > 0 ? (int) round($totalTokens / $totalRequests) : 0;
        $avgDurationMs = $totalRequests > 0 ? (int) round($totalDurationMs / $totalRequests) : 0;
        $successRate = $totalRequests > 0 ? round(($successRequests / $totalRequests) * 100, 1) : 0;
        $totalCostIdr = (int) round($totalCostUsd * self::USD_TO_IDR);

        // Timeline data for trend chart
        $timeline = $this->buildTimeline($records, $range, $startDate);

        // Model breakdown
        $modelBreakdown = $this->buildModelBreakdown($records);

        // Agent breakdown
        $agentBreakdown = $this->buildAgentBreakdown($records);

        // Recent 15 requests
        $recentRequests = $records->take(15)->map(function ($r) {
            $u = $r->usage ?? [];
            $rates = $this->getModelRates((string) $r->model);
            $prompt = (int) ($u['prompt_tokens'] ?? 0);
            $completion = (int) ($u['completion_tokens'] ?? 0);
            $costUsd = ($prompt * ($rates['input'] / 1_000_000)) + ($completion * ($rates['output'] / 1_000_000));

            return [
                'id' => $r->id,
                'publication_id' => $r->publication_id,
                'publication_title' => $r->publication?->title ?? 'Publikasi',
                'physical_page' => $r->page?->physical_page,
                'agent_slot' => $r->agent?->slot,
                'agent_name' => $r->agent?->name ?? 'Agen AI',
                'model' => $r->model,
                'status' => $r->status,
                'is_success' => ! str_starts_with((string) $r->status, 'failed'),
                'duration_ms' => $r->duration_ms,
                'tokens' => (int) ($u['total_tokens'] ?? ($prompt + $completion)),
                'cost_usd' => round($costUsd, 5),
                'cost_idr' => (int) round($costUsd * self::USD_TO_IDR),
                'created_at_human' => $r->created_at?->diffForHumans() ?? '',
                'created_at_time' => $r->created_at?->format('H:i:s d/m') ?? '',
            ];
        })->values()->all();

        return [
            'range' => $range,
            'summary' => [
                'total_requests' => $totalRequests,
                'success_requests' => $successRequests,
                'failed_requests' => $failedRequests,
                'success_rate' => $successRate,
                'total_prompt_tokens' => $totalPromptTokens,
                'total_completion_tokens' => $totalCompletionTokens,
                'total_tokens' => $totalTokens,
                'avg_tokens_per_request' => $avgTokensPerReq,
                'avg_duration_ms' => $avgDurationMs,
                'estimated_cost_usd' => round($totalCostUsd, 4),
                'estimated_cost_idr' => $totalCostIdr,
            ],
            'timeline' => $timeline,
            'model_breakdown' => $modelBreakdown,
            'agent_breakdown' => $agentBreakdown,
            'recent_requests' => $recentRequests,
        ];
    }

    private function buildTimeline(Collection $records, string $range, ?Carbon $startDate): array
    {
        if ($range === '24h') {
            // Group by hour (last 24 hours)
            $slots = [];
            for ($i = 23; $i >= 0; $i--) {
                $t = now()->subHours($i);
                $key = $t->format('Y-m-d H');
                $slots[$key] = [
                    'label' => $t->format('H:00'),
                    'full_date' => $t->format('d M H:00'),
                    'requests' => 0,
                    'tokens' => 0,
                    'cost_usd' => 0.0,
                    'failures' => 0,
                    'duration_total' => 0,
                ];
            }

            foreach ($records as $r) {
                if (! $r->created_at) continue;
                $key = $r->created_at->format('Y-m-d H');
                if (isset($slots[$key])) {
                    $u = $r->usage ?? [];
                    $prompt = (int) ($u['prompt_tokens'] ?? 0);
                    $comp = (int) ($u['completion_tokens'] ?? 0);
                    $tok = (int) ($u['total_tokens'] ?? ($prompt + $comp));
                    $rates = $this->getModelRates((string) $r->model);
                    $cost = ($prompt * ($rates['input'] / 1_000_000)) + ($comp * ($rates['output'] / 1_000_000));

                    $slots[$key]['requests']++;
                    $slots[$key]['tokens'] += $tok;
                    $slots[$key]['cost_usd'] += $cost;
                    $slots[$key]['duration_total'] += (int) $r->duration_ms;
                    if (str_starts_with((string) $r->status, 'failed')) {
                        $slots[$key]['failures']++;
                    }
                }
            }

            return array_values(array_map(function ($s) {
                $avgDur = $s['requests'] > 0 ? (int) round($s['duration_total'] / $s['requests']) : 0;
                return [
                    'label' => $s['label'],
                    'full_date' => $s['full_date'],
                    'requests' => $s['requests'],
                    'tokens' => $s['tokens'],
                    'cost_usd' => round($s['cost_usd'], 4),
                    'cost_idr' => (int) round($s['cost_usd'] * self::USD_TO_IDR),
                    'failures' => $s['failures'],
                    'avg_duration_ms' => $avgDur,
                ];
            }, $slots));
        }

        // For 3d, 7d, 30d, or all: group by day
        $dayCount = match ($range) {
            '3d' => 3,
            '7d' => 7,
            '30d' => 30,
            default => 30,
        };

        if ($range === 'all') {
            $earliest = $records->min('created_at');
            $dayCount = $earliest ? max(7, min(90, (int) ceil(now()->diffInDays($earliest)) + 1)) : 7;
        }

        $slots = [];
        for ($i = $dayCount - 1; $i >= 0; $i--) {
            $d = now()->subDays($i);
            $key = $d->format('Y-m-d');
            $slots[$key] = [
                'label' => $d->format('d M'),
                'full_date' => $d->format('d M Y'),
                'requests' => 0,
                'tokens' => 0,
                'cost_usd' => 0.0,
                'failures' => 0,
                'duration_total' => 0,
            ];
        }

        foreach ($records as $r) {
            if (! $r->created_at) continue;
            $key = $r->created_at->format('Y-m-d');
            if (isset($slots[$key])) {
                $u = $r->usage ?? [];
                $prompt = (int) ($u['prompt_tokens'] ?? 0);
                $comp = (int) ($u['completion_tokens'] ?? 0);
                $tok = (int) ($u['total_tokens'] ?? ($prompt + $comp));
                $rates = $this->getModelRates((string) $r->model);
                $cost = ($prompt * ($rates['input'] / 1_000_000)) + ($comp * ($rates['output'] / 1_000_000));

                $slots[$key]['requests']++;
                $slots[$key]['tokens'] += $tok;
                $slots[$key]['cost_usd'] += $cost;
                $slots[$key]['duration_total'] += (int) $r->duration_ms;
                if (str_starts_with((string) $r->status, 'failed')) {
                    $slots[$key]['failures']++;
                }
            }
        }

        return array_values(array_map(function ($s) {
            $avgDur = $s['requests'] > 0 ? (int) round($s['duration_total'] / $s['requests']) : 0;
            return [
                'label' => $s['label'],
                'full_date' => $s['full_date'],
                'requests' => $s['requests'],
                'tokens' => $s['tokens'],
                'cost_usd' => round($s['cost_usd'], 4),
                'cost_idr' => (int) round($s['cost_usd'] * self::USD_TO_IDR),
                'failures' => $s['failures'],
                'avg_duration_ms' => $avgDur,
            ];
        }, $slots));
    }

    private function buildModelBreakdown(Collection $records): array
    {
        $grouped = $records->groupBy(fn ($r) => (string) ($r->model ?: 'unknown'));

        return $grouped->map(function ($group, $model) {
            $total = $group->count();
            $success = $group->where(fn ($r) => ! str_starts_with((string) $r->status, 'failed'))->count();
            $tokens = 0;
            $durations = 0;
            $costUsd = 0.0;
            $rates = $this->getModelRates($model);

            foreach ($group as $r) {
                $u = $r->usage ?? [];
                $p = (int) ($u['prompt_tokens'] ?? 0);
                $c = (int) ($u['completion_tokens'] ?? 0);
                $tokens += (int) ($u['total_tokens'] ?? ($p + $c));
                $durations += (int) $r->duration_ms;
                $costUsd += ($p * ($rates['input'] / 1_000_000)) + ($c * ($rates['output'] / 1_000_000));
            }

            return [
                'model' => $model,
                'requests' => $total,
                'success' => $success,
                'failed' => $total - $success,
                'success_rate' => $total > 0 ? round(($success / $total) * 100, 1) : 0,
                'tokens' => $tokens,
                'avg_duration_ms' => $total > 0 ? (int) round($durations / $total) : 0,
                'cost_usd' => round($costUsd, 4),
                'cost_idr' => (int) round($costUsd * self::USD_TO_IDR),
            ];
        })->sortByDesc('requests')->values()->all();
    }

    private function buildAgentBreakdown(Collection $records): array
    {
        $agents = AiAgent::orderBy('slot')->get()->keyBy('id');
        $grouped = $records->groupBy('ai_agent_id');

        $list = [];
        foreach ($grouped as $agentId => $group) {
            $agent = $agentId ? $agents->get($agentId) : null;
            $total = $group->count();
            $success = $group->where(fn ($r) => ! str_starts_with((string) $r->status, 'failed'))->count();
            $tokens = 0;
            $durations = 0;
            $costUsd = 0.0;

            foreach ($group as $r) {
                $u = $r->usage ?? [];
                $p = (int) ($u['prompt_tokens'] ?? 0);
                $c = (int) ($u['completion_tokens'] ?? 0);
                $tokens += (int) ($u['total_tokens'] ?? ($p + $c));
                $durations += (int) $r->duration_ms;
                $rates = $this->getModelRates((string) $r->model);
                $costUsd += ($p * ($rates['input'] / 1_000_000)) + ($c * ($rates['output'] / 1_000_000));
            }

            $list[] = [
                'agent_id' => $agentId,
                'slot' => $agent?->slot ?? 0,
                'name' => $agent?->name ?? ($agentId ? "Agen #{$agentId}" : 'Tanpa Agen'),
                'model' => $agent?->model ?? ($group->first()?->model ?? 'unknown'),
                'requests' => $total,
                'success' => $success,
                'failed' => $total - $success,
                'success_rate' => $total > 0 ? round(($success / $total) * 100, 1) : 0,
                'tokens' => $tokens,
                'avg_duration_ms' => $total > 0 ? (int) round($durations / $total) : 0,
                'cost_usd' => round($costUsd, 4),
                'cost_idr' => (int) round($costUsd * self::USD_TO_IDR),
            ];
        }

        usort($list, fn ($a, $b) => $b['requests'] <=> $a['requests']);

        return $list;
    }

    private function getModelRates(string $model): array
    {
        $m = strtolower(trim($model));
        foreach (self::MODEL_PRICING as $key => $rates) {
            if ($key !== 'default' && str_contains($m, $key)) {
                return $rates;
            }
        }

        return self::MODEL_PRICING['default'];
    }
}
