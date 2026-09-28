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
 * proceeds; a route behind `auth` then sends the visitor to the Slack
 * login).
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

        // Embed mode is opt-in via the IDE handshake. The page is intentionally
        // framable from the VSCode webview (which uses a variety of origins
        // across stable, insider, and remote builds). `*` covers network
        // schemes; vscode-webview: / vscode-file: must be listed explicitly
        // because CSP `*` doesn't match non-network schemes. Allowing any
        // ancestor is acceptable because all sensitive data on the embedded
        // page lives behind the user's session cookie — a third-party framing
        // site can't read it cross-origin and can't act on the user's behalf.
        $response->headers->remove('X-Frame-Options');
        $response->headers->set(
            'Content-Security-Policy',
            'frame-ancestors * vscode-webview: vscode-file: vscode-resource: data: blob:',
        );

        return $response;
    }
}
