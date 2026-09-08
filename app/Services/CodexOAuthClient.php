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
     * @var string
     */
    private const string USERCODE_URL = 'https://auth.openai.com/api/accounts/deviceauth/usercode';

    /**
     * @var string
     */
    private const string DEVICE_TOKEN_URL = 'https://auth.openai.com/api/accounts/deviceauth/token';

    /**
     * @var string
     */
    private const string OAUTH_TOKEN_URL = 'https://auth.openai.com/oauth/token';

    /**
     * @var string
     */
    private const string DEVICE_REDIRECT_URI = 'https://auth.openai.com/deviceauth/callback';

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

    /**
     * Step 1 of the device-code flow: request a fresh user code the human
     * enters at `https://auth.openai.com/codex/device`.
     *
     * @return array{device_auth_id: string, user_code: string, interval: int, expires_at: string}
     */
    public function requestUserCode(): array
    {
        // JSON, not asForm(): this is OpenAI's own custom deviceauth API, not
        // a standards-track OAuth endpoint. Verified live -- a form-encoded
        // body gets a 400 ("Input should be a valid dictionary or object to
        // extract fields from", a Pydantic body-parse error, not a field
        // validation error) from the real endpoint.
        return Http::post(self::USERCODE_URL, [
            'client_id' => self::CLIENT_ID,
        ])->throw()->json();
    }

    /**
     * Step 2/3 of the device-code flow: one poll of the token endpoint.
     * Returns `{status: 'deviceauth_authorization_pending'}` while the
     * human hasn't approved yet, or the full success payload
     * (`authorization_code`/`code_challenge`/`code_verifier`) once they
     * have. Never throws on the pending case — that response is a real
     * 400 the caller is expected to poll past.
     *
     * @param  string  $deviceAuthId  the device_auth_id from {@see requestUserCode()}
     * @param  string  $userCode  the user_code from {@see requestUserCode()}
     * @return array{status: string, authorization_code?: string, code_challenge?: string, code_verifier?: string}
     */
    public function pollDeviceToken(string $deviceAuthId, string $userCode): array
    {
        // JSON, not asForm() -- same custom deviceauth API as requestUserCode(),
        // confirmed live to reject a form-encoded body the same way.
        $response = Http::post(self::DEVICE_TOKEN_URL, [
            'device_auth_id' => $deviceAuthId,
            'user_code' => $userCode,
        ]);

        $body = $response->json();

        if (! $response->successful()) {
            return ['status' => $body['error']['code'] ?? 'unknown_error'];
        }

        return $body;
    }

    /**
     * Step 4 of the device-code flow: exchange the approved
     * authorization_code for real tokens, at the fixed OpenAI-hosted
     * device-code redirect_uri (never localhost).
     *
     * @param  string  $code  the authorization_code from {@see pollDeviceToken()}
     * @param  string  $codeVerifier  the code_verifier from {@see pollDeviceToken()}
     * @return array<string, mixed> the decoded token response
     */
    public function exchangeAuthorizationCode(string $code, string $codeVerifier): array
    {
        return Http::asForm()->post(self::OAUTH_TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $codeVerifier,
            'redirect_uri' => self::DEVICE_REDIRECT_URI,
            'client_id' => self::CLIENT_ID,
        ])->throw()->json();
    }
}
