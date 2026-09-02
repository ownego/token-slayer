<?php

namespace App\Http\Middleware;

use App\Models\IdeAccessToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates admin-only API routes via an `IdeAccessToken` of kind
 * `admin_bearer` — resolved only for a user who still holds a role (see
 * {@see IdeAccessToken::resolveAdminBearer()}).
 */
class AuthenticateAdminBearer
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();

        if ($plain === null) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $tokenExists = IdeAccessToken::query()
            ->where('kind', 'admin_bearer')
            ->where('token_hash', hash('sha256', $plain))
            ->whereNull('revoked_at')
            ->exists();

        $user = IdeAccessToken::resolveAdminBearer($plain);

        if ($user === null) {
            return response()->json(['error' => $tokenExists ? 'forbidden' : 'unauthenticated'], $tokenExists ? 403 : 401);
        }

        $request->setUserResolver(fn () => $user);
        auth()->setUser($user);

        return $next($request);
    }
}
