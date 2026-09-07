<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateHookToken
{
    /**
     * Handle an incoming request.
     *
     * `$capability` is an optional route middleware parameter
     * (`'hook.token:admin'`). A hook token identifies the caller only — it
     * is issued to every employee at first login, machine-wide, and by
     * itself proves nothing about what they're allowed to do. Passing
     * `'admin'` additionally requires the resolved user to currently hold a
     * role, re-checked on every request the same way an admin-only bearer
     * token would have to be: a role revoked after the fact must stop
     * authorizing immediately, not just at the panel's own gate.
     *
     * @param  Closure(Request): (Response)  $next
     * @param  string|null  $capability  `'admin'` to additionally require a role, or null for identity only
     */
    public function handle(Request $request, Closure $next, ?string $capability = null): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $user = User::where('hook_token', hash('sha256', $token))->first();

        if (! $user) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        if ($capability === 'admin' && ! $user->roles()->exists()) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        $request->setUserResolver(fn ($guard = null) => $guard === 'hook' ? $user : null);

        return $next($request);
    }
}
