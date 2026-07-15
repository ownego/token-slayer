<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends an unauthenticated visitor straight into the Slack OAuth flow
 * instead of Filament's default login page — this panel never registers
 * ->login(), so every user reaches /admin already authenticated via Slack
 * on the shared `web` guard, or not at all.
 */
class RedirectGuestsToSlackLogin
{
    /**
     * Redirects guests to the Slack OAuth login route instead of letting
     * them fall through to Filament's (unregistered) password login page.
     *
     * @param  Request  $request  the incoming request
     * @param  Closure(Request): Response  $next  the next middleware in the stack
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('web')->guest()) {
            return redirect()->route('slack.login');
        }

        return $next($request);
    }
}
