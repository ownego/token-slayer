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
 *
 * In practice this rarely fires for a genuine guest: Filament's own
 * `Authenticate` middleware runs earlier in `authMiddleware` and throws
 * first, so its redirect target — `bootstrap/app.php`'s app-wide
 * `redirectGuestsTo()` closure, not this class — is what visitors actually
 * hit. Kept as a defensive fallback for any request that reaches this
 * middleware already past that check.
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
            // `guest()` stashes the current URL in `session('url.intended')`
            // so the Slack callback can send the user back where they were
            // headed (redirect()->intended()) instead of a fixed landing page.
            return redirect()->guest(route('slack.login'));
        }

        return $next($request);
    }
}
