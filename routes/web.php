<?php

declare(strict_types=1);

use App\Http\Controllers\AuthController;
use App\Http\Controllers\FindingController;
use App\Http\Controllers\PublicationController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:login');
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
    Route::post('/publications/{publication}/exports', [PublicationController::class, 'export']);
    Route::post('/publications/{publication}/exports/partial', [PublicationController::class, 'exportPartial'])->middleware('throttle:5,1');
    Route::get('/publications/{publication}/exports/{export}', [PublicationController::class, 'download']);
    Route::post('/publications/{publication}/assign', [PublicationController::class, 'assign']);
    Route::post('/publications/{publication}/lock', [PublicationController::class, 'lock']);
    Route::post('/publications/{publication}/unlock', [PublicationController::class, 'unlock']);
    Route::post('/publications/{publication}/findings', [FindingController::class, 'store']);
    Route::patch('/publications/{publication}/findings/{finding}', [FindingController::class, 'update']);
    Route::get('/publications/{publication}/findings/{finding}/history', [FindingController::class, 'history']);
    Route::get('/settings', [SettingsController::class, 'index']);
    Route::post('/settings/users', [SettingsController::class, 'createUser']);
    Route::put('/settings/users/{user}', [SettingsController::class, 'updateUser']);
    Route::post('/settings/ai', [SettingsController::class, 'saveAi']);
    Route::post('/settings/ai/test', [SettingsController::class, 'testAi'])->middleware('throttle:5,1');
});
