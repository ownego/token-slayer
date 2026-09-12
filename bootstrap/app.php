<?php

use App\Http\Middleware\AuthenticateHookToken;
use App\Http\Middleware\AuthenticateIdeBearer;
use App\Http\Middleware\EstablishIdeSession;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'hook.token' => AuthenticateHookToken::class,
            'ide.bearer' => AuthenticateIdeBearer::class,
        ]);

        $middleware->web(append: [
            EstablishIdeSession::class,
        ]);

        // A Livewire request (a panel widget's background poll, not a real
        // page load) must not be sent through a real Socialite redirect: the
        // browser's fetch() auto-follows a same-origin 302 across origins,
        // so every poll tick silently burns a real round trip to Slack's
        // authorize endpoint without ever landing the visitor anywhere —
        // invisible, since the address bar never changes. Observed live: one
        // dashboard load spawned 500+ such hits in 20 minutes. 419 is
        // Livewire's own "session expired" status; its client shows a
        // refresh prompt instead of retrying immediately.
        $middleware->redirectGuestsTo(function ($request) {
            if ($request->hasHeader('X-Livewire')) {
                abort(419);
            }

            return route('slack.login');
        });

        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
