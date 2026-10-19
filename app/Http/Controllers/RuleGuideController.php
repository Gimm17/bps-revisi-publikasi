<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Audit\RuleDocumentationService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RuleGuideController extends Controller
{
    public function __construct(
        private readonly RuleDocumentationService $documentationService
    ) {}

    public function index(Request $request): Response
    {
        $rulesData = $this->documentationService->getRulesData();

        return Inertia::render('Rules/Index', [
            'version' => $rulesData['version'],
            'target_version' => $rulesData['target_version'],
            'summary' => $rulesData['summary'],
            'categories' => $rulesData['categories'],
            'stages' => $rulesData['stages'],
            'rules' => $rulesData['rules'],
        ]);
    }
}
