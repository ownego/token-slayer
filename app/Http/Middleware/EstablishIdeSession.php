<?php

namespace App\Http\Middleware;

use App\Models\IdeAccessToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles two IDE-embed concerns on web requests:
 *
 * 1. When a request carries `?_t=<one_shot>`, validate it, log the user in
 *    for the session, and 302-redirect to the same URL without the token
 *    query parameter. The iframe in the VSCode webview lands on the clean
 *    URL with a working session cookie.
 * 2. When a request carries `?embed=ide`, strip the upstream
 *    `X-Frame-Options: SAMEORIGIN` header so the page can be hosted inside
 *    the VSCode webview iframe. Replaces it with a CSP frame-ancestors
 *    directive that whitelists the VSCode webview origins.
 *
 * Invalid/expired one-shot tokens silently no-op (the request still
 * proceeds so public routes like /battlefield remain reachable).
 */
class EstablishIdeSession
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->query('_t');

        if (is_string($token) && $token !== '') {
            $consumed = IdeAccessToken::consumeSessionUrl($token);

            if ($consumed !== null) {
                auth()->login($consumed['user']);

                return $this->relaxFramingFor(
                    $request,
                    redirect()->to(url($consumed['redirectPath'])),
                );
            }
        }

        return $this->relaxFramingFor($request, $next($request));
    }

    private function relaxFramingFor(Request $request, Response $response): Response
    {
        if ($request->query('embed') !== 'ide') {
            return $response;
        }

        $response->headers->remove('X-Frame-Options');
        $response->headers->set(
            'Content-Security-Policy',
            "frame-ancestors 'self' vscode-webview: https://*.vscode-cdn.net",
        );

        return $response;
    }
}
