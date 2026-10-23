<?php

declare(strict_types=1);

use App\Http\Controllers\AgentOfficeController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DocumentationController;
use App\Http\Controllers\FindingController;
use App\Http\Controllers\PublicationCheckController;
use App\Http\Controllers\PublicationController;
use App\Http\Controllers\RuleGuideController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:login');
});

// Publicly accessible rules & documentation for visitors & search engine indexation
Route::get('/rules', [RuleGuideController::class, 'index'])->name('rules');
Route::get('/guidelines', [RuleGuideController::class, 'index']);
Route::get('/documentation', [DocumentationController::class, 'index'])->name('documentation');
Route::get('/docs', [DocumentationController::class, 'index']);

// Developer autologin for automated testing & mobile verification
Route::get('/dev-autologin', function () {
    $user = \App\Models\User::first();
    if ($user) {
        \Illuminate\Support\Facades\Auth::login($user);
    }
    return redirect('/');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'destroy']);
    Route::get('/', [PublicationController::class, 'index'])->name('dashboard');
    Route::get('/publications', [PublicationController::class, 'index']);
    Route::get('/publications/create', [PublicationController::class, 'create']);
    Route::post('/publications', [PublicationController::class, 'store'])->middleware('throttle:20,1');
    Route::get('/publications/{publication}', [PublicationController::class, 'show']);
    Route::post('/publications/{publication}/start', [PublicationController::class, 'start'])->middleware('throttle:10,1');
    Route::post('/publications/{publication}/rebind-ai', [PublicationController::class, 'rebindAi'])->middleware('throttle:10,1');
    Route::post('/publications/{publication}/pages/{page}/retry', [PublicationController::class, 'retry']);
    Route::get('/publications/{publication}/pdf', [PublicationController::class, 'pdf']);
    Route::get('/publications/{publication}/pages/{page}/image', [PublicationController::class, 'image']);
    Route::post('/publications/{publication}/checks', [PublicationCheckController::class, 'store']);
    Route::post('/publications/{publication}/checks/{check}/retry', [PublicationCheckController::class, 'retry']);
    Route::post('/publications/{publication}/references', [PublicationCheckController::class, 'storeReference']);
    Route::get('/publications/{publication}/references/{reference}/artifacts/{artifact}', [PublicationCheckController::class, 'artifact']);
    Route::patch('/publications/{publication}/assessments/{assessment}', [PublicationCheckController::class, 'updateAssessment']);
    Route::post('/publications/{publication}/exports', [PublicationController::class, 'export']);
    Route::post('/publications/{publication}/exports/partial', [PublicationController::class, 'exportPartial'])->middleware('throttle:5,1');
    Route::get('/publications/{publication}/exports/{export}', [PublicationController::class, 'download']);
    Route::post('/publications/{publication}/exports/{export}/reviewed', [PublicationController::class, 'uploadReviewedExport']);
    Route::get('/learnings', [\App\Http\Controllers\LearningController::class, 'index'])->name('learnings');
    Route::patch('/learnings/{learning}', [\App\Http\Controllers\LearningController::class, 'update']);
    Route::post('/publications/{publication}/assign', [PublicationController::class, 'assign']);
    Route::post('/publications/{publication}/lock', [PublicationController::class, 'lock']);
    Route::post('/publications/{publication}/unlock', [PublicationController::class, 'unlock']);
    Route::post('/publications/{publication}/findings', [FindingController::class, 'store']);
    Route::patch('/publications/{publication}/findings/{finding}', [FindingController::class, 'update']);
    Route::get('/publications/{publication}/findings/{finding}/history', [FindingController::class, 'history']);
    Route::patch('/publications/{publication}/cross-verify-mode', [PublicationController::class, 'updateCrossVerifyMode']);
    Route::get('/settings', [SettingsController::class, 'index']);
    Route::post('/settings/users', [SettingsController::class, 'createUser']);
    Route::put('/settings/users/{user}', [SettingsController::class, 'updateUser']);
    Route::post('/settings/ai', [SettingsController::class, 'saveAi']);
    Route::post('/settings/ai/test', [SettingsController::class, 'testAi'])->middleware('throttle:5,1');
    Route::post('/settings/ai-agents/copy', [SettingsController::class, 'copyAgent']);
    Route::post('/settings/ai-agents/test-all', [SettingsController::class, 'testAllAiAgents'])->middleware('throttle:10,1');
    Route::get('/settings/ai-agents/status', [SettingsController::class, 'aiAgentsStatus']);
    Route::get('/settings/ai-telemetry', [SettingsController::class, 'aiTelemetry']);
    Route::post('/settings/ai-agents/{slot}', [SettingsController::class, 'saveAiAgent']);
    Route::post('/settings/ai-agents/{agent}/test', [SettingsController::class, 'testAiAgent'])->middleware('throttle:10,1');
    Route::post('/settings/reference-provider', [SettingsController::class, 'saveReferenceProvider']);

    Route::get('/agent-office', [AgentOfficeController::class, 'index'])->name('agent-office.index');
    Route::get('/api/agent-office/state', [AgentOfficeController::class, 'state'])->name('agent-office.state');
    Route::post('/agent-office/{agent}/test', [AgentOfficeController::class, 'testAgent'])->name('agent-office.test');
    Route::post('/agent-office/test-all', [AgentOfficeController::class, 'testAll'])->name('agent-office.test-all');
});
