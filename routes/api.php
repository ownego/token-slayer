<?php

use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\Ide\MeController;
use App\Http\Controllers\Api\StateController;
use Illuminate\Support\Facades\Route;

Route::post('/events', [EventController::class, 'store'])
    ->middleware('hook.token');

Route::get('/state', [StateController::class, 'show']);

Route::middleware('ide.bearer')->prefix('ide')->group(function (): void {
    Route::get('/me', MeController::class);
});
