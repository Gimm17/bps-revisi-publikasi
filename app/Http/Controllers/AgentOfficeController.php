<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\TestAiAgent;
use App\Models\AiAgent;
use App\Models\AiUsageRecord;
use App\Models\Publication;
use App\Models\PublicationPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AgentOfficeController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('AgentOffice/Index', [
            'initialState' => $this->buildState(),
        ]);
    }

    public function state(Request $request): JsonResponse
    {
        return response()->json($this->buildState());
    }

    public function testAgent(Request $request, AiAgent $agent): JsonResponse|RedirectResponse
    {
        TestAiAgent::dispatch($agent->id);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Pengujian agen {$agent->name} (Slot {$agent->slot}) telah dikirim ke antrean.",
            ]);
        }

        return back()->with('status', "Pengujian agen {$agent->name} (Slot {$agent->slot}) telah dikirim ke antrean.");
    }

    public function testAll(Request $request): JsonResponse|RedirectResponse
    {
        $agents = AiAgent::where('enabled', true)->get();
        foreach ($agents as $agent) {
            TestAiAgent::dispatch($agent->id);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Pengujian seluruh agen aktif telah dikirim ke antrean.',
            ]);
        }

        return back()->with('status', 'Pengujian seluruh agen aktif telah dikirim ke antrean.');
    }

    private function buildState(): array
    {
        // Active publications that are genuinely being processed right now
        $activePublications = Publication::whereIn('status', [
            'processing',
            'processing_machine',
            'processing_ai',
            'reconciling',
            'analyzing',
            'cross_checking',
            'exporting',
        ])
            ->latest('updated_at')
            ->get(['id', 'title', 'status', 'updated_at']);

        $activePublicationIds = $activePublications->pluck('id')->all();
        $hasActiveAudit = count($activePublicationIds) > 0;
        $primaryActivePub = $activePublications->first();

        // Active publication page totals (if an audit is currently running)
        $activePubTotalPages = $hasActiveAudit
            ? PublicationPage::whereIn('publication_id', $activePublicationIds)->count()
            : 0;
        $activePubCompletedPages = $hasActiveAudit
            ? PublicationPage::whereIn('publication_id', $activePublicationIds)->where('status', 'complete')->count()
            : 0;

        $agents = AiAgent::where('slot', '<=', 5)->orderBy('slot')->get()->map(function (AiAgent $agent) use ($hasActiveAudit, $activePublicationIds, $primaryActivePub) {
            $lifetimeCompletedPages = PublicationPage::where('ai_agent_id', $agent->id)
                ->where('status', 'complete')
                ->count();

            // Pages completed by this agent in the currently running audit(s)
            $activeAuditAgentPages = $hasActiveAudit
                ? PublicationPage::whereIn('publication_id', $activePublicationIds)
                    ->where('ai_agent_id', $agent->id)
                    ->where('status', 'complete')
                    ->count()
                : 0;

            // Check genuinely active analyzing page (must belong to an active publication and be updated within last 5 minutes)
            $activePage = null;
            if ($hasActiveAudit) {
                $activePage = PublicationPage::where('ai_agent_id', $agent->id)
                    ->where('status', 'analyzing')
                    ->whereIn('publication_id', $activePublicationIds)
                    ->where('updated_at', '>=', now()->subMinutes(5))
                    ->with('publication:id,title,status')
                    ->latest('updated_at')
                    ->first();
            }

            // Most recent usage record for this agent
            $lastUsage = AiUsageRecord::where('ai_agent_id', $agent->id)
                ->with(['publication:id,title,status', 'page:id,physical_page'])
                ->latest('id')
                ->first();

            $secondsSinceLastUsage = $lastUsage?->created_at
                ? (int) abs(now()->diffInSeconds($lastUsage->created_at))
                : null;

            $totalCalls = AiUsageRecord::where('ai_agent_id', $agent->id)->count();
            $avgDuration = (int) round((float) (AiUsageRecord::where('ai_agent_id', $agent->id)->avg('duration_ms') ?: 0));

            // Determine accurate real-time office_status:
            // 'analyzing' | 'submitting' | 'cooldown' | 'standby_queue' | 'testing' | 'idle' | 'offline' | 'error'
            $officeStatus = 'idle';
            if (! $agent->enabled) {
                $officeStatus = 'offline';
            } elseif ($agent->status === 'testing') {
                $officeStatus = 'testing';
            } elseif ($activePage) {
                $officeStatus = 'analyzing';
            } elseif ($hasActiveAudit && $secondsSinceLastUsage !== null && $secondsSinceLastUsage <= 12 && str_contains((string) $lastUsage->status, 'capacity')) {
                $officeStatus = 'cooldown';
            } elseif ($hasActiveAudit && $secondsSinceLastUsage !== null && $secondsSinceLastUsage <= 6) {
                $officeStatus = 'submitting';
            } elseif ($hasActiveAudit && $agent->status === 'ready') {
                $officeStatus = 'standby_queue';
            } elseif ($agent->status !== 'ready') {
                $officeStatus = 'error';
            }

            return [
                'id' => $agent->id,
                'slot' => $agent->slot,
                'name' => $agent->name,
                'model' => $agent->model,
                'base_url' => $agent->base_url,
                'enabled' => $agent->enabled,
                'db_status' => $agent->status,
                'office_status' => $officeStatus,
                'test_message' => $agent->test_message,
                'vision_tested_at' => $agent->vision_tested_at?->diffForHumans() ?? 'Belum diuji',
                'assigned_pages' => $lifetimeCompletedPages,
                'completed_pages' => $lifetimeCompletedPages,
                'active_audit_completed_pages' => $activeAuditAgentPages,
                'total_calls' => $totalCalls,
                'avg_duration_ms' => $avgDuration,
                'active_task' => $activePage ? [
                    'page_id' => $activePage->id,
                    'physical_page' => $activePage->physical_page,
                    'publication_id' => $activePage->publication_id,
                    'publication_title' => $activePage->publication?->title ?? 'Publikasi',
                    'started_at' => $activePage->updated_at?->toIso8601String(),
                ] : null,
                'last_activity' => $lastUsage ? [
                    'publication_title' => $lastUsage->publication?->title ?? 'Publikasi',
                    'physical_page' => $lastUsage->page?->physical_page,
                    'status' => $lastUsage->status,
                    'duration_ms' => $lastUsage->duration_ms,
                    'seconds_ago' => $secondsSinceLastUsage,
                    'time_human' => $lastUsage->created_at?->diffForHumans() ?? '',
                    'time_clock' => $lastUsage->created_at?->format('H:i:s') ?? '',
                ] : null,
                'active_publication_title' => $primaryActivePub?->title,
            ];
        });

        $recentRecords = AiUsageRecord::with(['agent:id,slot,name,model', 'page:id,physical_page', 'publication:id,title,status'])
            ->latest('id')
            ->take(20)
            ->get()
            ->map(function (AiUsageRecord $record) use ($activePublicationIds) {
                $secondsAgo = $record->created_at ? (int) abs(now()->diffInSeconds($record->created_at)) : 9999;
                $isLive = in_array($record->publication_id, $activePublicationIds, true) && $secondsAgo <= 60;

                return [
                    'id' => $record->id,
                    'agent_slot' => $record->agent?->slot,
                    'agent_name' => $record->agent?->name ?? 'Agen AI',
                    'model' => $record->model,
                    'publication_title' => $record->publication?->title ?? 'Publikasi',
                    'physical_page' => $record->page?->physical_page,
                    'status' => $record->status,
                    'duration_ms' => $record->duration_ms,
                    'is_live' => $isLive,
                    'created_at_human' => $record->created_at?->diffForHumans() ?? 'Baru saja',
                    'created_at_time' => $record->created_at?->format('H:i:s') ?? '',
                ];
            });

        $totalProcessedPages = PublicationPage::where('status', 'complete')->whereNotNull('ai_agent_id')->count();
        $workingAgentsCount = $agents->whereIn('office_status', ['analyzing', 'submitting', 'testing'])->count();
        $standbyReadyCount = $agents->where('enabled', true)->whereNotIn('office_status', ['offline', 'error'])->count();
        $backupAgent = AiAgent::where('slot', 6)->first();

        return [
            'agents' => $agents,
            'recent_activities' => $recentRecords,
            'summary' => [
                'has_active_audit' => $hasActiveAudit,
                'active_publication_title' => $primaryActivePub?->title,
                'active_publication_status' => $primaryActivePub?->status,
                'active_pub_total_pages' => $activePubTotalPages,
                'active_pub_completed_pages' => $activePubCompletedPages,
                'total_agents' => $agents->count(),
                'active_agents' => $workingAgentsCount,
                'ready_agents' => $standbyReadyCount,
                'active_audits' => count($activePublicationIds),
                'total_pages_processed' => $totalProcessedPages,
                'backup_agent' => $backupAgent ? [
                    'id' => $backupAgent->id,
                    'name' => $backupAgent->name,
                    'model' => $backupAgent->model,
                    'enabled' => (bool) $backupAgent->enabled,
                    'status' => $backupAgent->status,
                    'is_ready' => $backupAgent->isReady(),
                ] : null,
            ],
            'server_time' => now()->format('H:i:s'),
        ];
    }
}
