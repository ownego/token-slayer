<?php

use App\Http\Controllers\Api\Admin\CodexAdminController;
use App\Http\Controllers\Api\Ccrc\ExchangeController as CcrcExchangeController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\Ide\AuthController;
use App\Http\Controllers\Api\Ide\HookConfigController;
use App\Http\Controllers\Api\Ide\MeController;
use App\Http\Controllers\Api\Ide\SnapshotController;
use App\Http\Controllers\Api\ProvisionedAccountController;
use App\Http\Controllers\Api\StateController;
use Illuminate\Support\Facades\Route;

Route::middleware('hook.token')->group(function (): void {
    Route::post('/events', [EventController::class, 'store']);

    Route::prefix('provisioned')->group(function (): void {
        Route::get('/', [ProvisionedAccountController::class, 'index']);
        Route::post('/confirm', [ProvisionedAccountController::class, 'confirm']);
    });
});

Route::get('/state', [StateController::class, 'show']);

Route::middleware('throttle:30,1')->prefix('ide')->group(function (): void {
    Route::post('/auth/exchange', [AuthController::class, 'exchange']);

    Route::middleware('ide.bearer')->group(function (): void {
        Route::post('/auth/revoke', [AuthController::class, 'revoke']);
        Route::post('/auth/session-url', [AuthController::class, 'sessionUrl']);
        Route::get('/me', MeController::class);
        Route::get('/hook-config', HookConfigController::class);
        Route::get('/snapshot', SnapshotController::class);
    });
});

Route::middleware('admin.bearer')->prefix('admin/codex')->group(function (): void {
    Route::post('/connect', [CodexAdminController::class, 'connect']);
    Route::post('/provision', [CodexAdminController::class, 'provision']);
});

// Entry point of the CCRC login flow. Same throttle as /api/ide, and NOT
// behind `ide.bearer`: this is the one-time token exchange step, before
// anyone holds a bearer yet.
Route::middleware('throttle:30,1')->prefix('ccrc')->group(function (): void {
    Route::post('/auth/exchange', CcrcExchangeController::class);
});
