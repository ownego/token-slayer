<?php

use App\Http\Controllers\Auth\SlackController;
use App\Http\Controllers\AvatarProxyController;
use App\Http\Controllers\HistoryController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('battlefield');
    }

    return view('welcome');
});

Route::get('/auth/slack', [SlackController::class, 'redirect'])->name('slack.login');
Route::get('/auth/slack/callback', [SlackController::class, 'callback']);

Route::get('/profile', fn () => view('profile'))->middleware('auth')->name('profile');

Route::get('/install', fn () => response(
    view('install-script', [
        'baseUrl' => url('/api/events'),
        'namespace' => config('app.hook_namespace'),
    ])->render(),
    200,
    ['Content-Type' => 'text/x-shellscript; charset=utf-8'],
))->name('install-script');

Route::get('/battlefield', fn () => view('battlefield'))->name('battlefield');

Route::get('/avatars/{user}', AvatarProxyController::class)->name('avatar');

Route::get('/history', [HistoryController::class, 'index'])->name('history');
