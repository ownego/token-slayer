<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client for OpenAI's OAuth revoke endpoint (RFC 7009-style) — the
 * only server-side call this phase makes to auth.openai.com. See the
 * codex-oauth-server-side-provisioning KB note for the confirmed endpoint
 * and client_id.
 */
class CodexOAuthClient
{
    /**
     * @var string
     */
    private const string REVOKE_URL = 'https://auth.openai.com/oauth/revoke';

    /**
     * @var string
     */
    private const string CLIENT_ID = 'app_EMoamEEZ73f0CkXaXp7hrann';

    /**
     * Revoke a Codex refresh token. Best-effort: OpenAI's response is not
     * consumed, and a failure here must never block the local grant revoke
     * it precedes — the caller marks the grant row Revoked regardless.
     *
     * @param  string  $refreshToken  the refresh token to revoke
     * @return void
     */
    public function revoke(string $refreshToken): void
    {
        try {
            Http::asForm()->post(self::REVOKE_URL, [
                'token' => $refreshToken,
                'token_type_hint' => 'refresh_token',
                'client_id' => self::CLIENT_ID,
            ]);
        } catch (ConnectionException) {
            // Best-effort — see docblock.
        }
    }
}
