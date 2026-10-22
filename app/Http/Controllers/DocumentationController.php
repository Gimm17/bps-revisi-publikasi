<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AiAgent;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DocumentationController extends Controller
{
    public function index(Request $request): Response
    {
        $concurrency = (int) config('audit.ai_concurrency', 5);
        $agents = AiAgent::orderBy('slot')->get(['id', 'name', 'slot', 'model', 'max_concurrency', 'status', 'enabled']);
        $ruleVersion = (string) config('audit.rule_version', 'bps-2026.10.05-v1');
        $targetRuleVersion = (string) config('audit.target_rule_version', 'bps-2026.10.18-v2');

        return Inertia::render('Documentation/Index', [
            'concurrency' => $concurrency,
            'rate_limit_rpm' => 25,
            'calculated_rpm' => 12.5,
            'rule_version' => $ruleVersion,
            'target_rule_version' => $targetRuleVersion,
            'agents' => $agents,
            'high_dpi' => (int) config('audit.high_dpi', 240),
            'default_dpi' => (int) config('audit.dpi', 160),
        ]);
    }
}
