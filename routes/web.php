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

Route::get('/admin/usage', fn () => view('admin-usage'))
    ->middleware(['auth', 'can:admin'])
    ->name('admin.usage');

Route::get('/install', fn () => response(
    view('install-script', [
        'baseUrl' => url('/api/events'),
        'namespace' => config('app.hook_namespace'),
        'clientVersion' => config('token_slayer.client_version'),
        'installUrl' => route('install-script'),
    ])->render(),
    200,
    ['Content-Type' => 'text/x-shellscript; charset=utf-8'],
))->name('install-script');

Route::get('/install-cowork', fn () => response(
    view('cowork-install-script', [
        'baseUrl' => url('/api/events').'?provider=cowork',
        'namespace' => config('app.hook_namespace'),
        'watcherUrl' => route('cowork-watcher'),
    ])->render(),
    200,
    ['Content-Type' => 'text/x-shellscript; charset=utf-8'],
))->name('cowork-install-script');

Route::get('/cowork-watcher.py', fn () => response(
    view('cowork-watcher', [
        'eventsUrl' => url('/api/events').'?provider=cowork',
        'namespace' => config('app.hook_namespace'),
    ])->render(),
    200,
    ['Content-Type' => 'text/x-python; charset=utf-8'],
))->name('cowork-watcher');

Route::get('/tracker.user.js', fn () => response(
    view('userscript', [
        'eventsUrl' => url('/api/events').'?provider=claude-ai',
        'appUrl' => url('/'),
        'appHost' => parse_url(url('/'), PHP_URL_HOST),
    ])->render(),
    200,
    ['Content-Type' => 'text/javascript; charset=utf-8'],
))->name('userscript');

Route::get('/battlefield', fn () => view('battlefield'))->name('battlefield');

Route::get('/avatars/{user}', AvatarProxyController::class)->name('avatar');

Route::get('/history', [HistoryController::class, 'index'])->name('history');
