<?php

namespace App\Services;

use App\Exceptions\UsageProbeException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client for Anthropic's OAuth token/usage/profile API, used by the
 * server-side quota prober and the account-connect flow.
 *
 * platform.claude.com's WAF returns a bare 429 (no Retry-After) for the
 * default Guzzle/curl/browser User-Agent, so every request sets a
 * claude-cli-style User-Agent from config('token_slayer.anthropic.user_agent').
 * Token/refresh grants are sent as JSON bodies, not form-encoded.
 */
class AnthropicOAuthClient
{
    /**
     * OAuth scopes requested by a refresh grant when the caller does not
     * specify its own, matching the five scopes captured live in
     * tests/fixtures/anthropic/refresh.json.
     *
     * @var array<int, string>
     */
    private const array DEFAULT_SCOPES = [
        'user:profile',
        'user:inference',
        'user:sessions:claude_code',
        'user:mcp_servers',
        'user:file_upload',
    ];

    /**
     * Exchange a PKCE authorization code for an access/refresh token pair.
     *
     * @param  string  $code  the authorization code returned by the OAuth callback
     * @param  string  $verifier  the PKCE code_verifier generated for this grant
     * @param  string  $state  the state value echoed back by the OAuth callback
     * @return array<string, mixed> the decoded token response (token.json shape)
     *
     * @throws UsageProbeException when the grant is rejected or the request fails
     */
    public function exchangeCode(string $code, string $verifier, string $state): array
    {
        return $this->postToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('token_slayer.anthropic.redirect_uri'),
            'client_id' => config('token_slayer.anthropic.oauth_client_id'),
            'code_verifier' => $verifier,
            'state' => $state,
        ]);
    }

    /**
     * Exchange a refresh token for a new access/refresh token pair, rotating
     * the refresh token.
     *
     * @param  string  $refreshToken  the current refresh token
     * @param  array<int, string>  $scopes  OAuth scopes to request; defaults to DEFAULT_SCOPES
     * @return array<string, mixed> the decoded token response (refresh.json shape)
     *
     * @throws UsageProbeException when the grant is rejected or the request fails
     */
    public function refresh(string $refreshToken, array $scopes = self::DEFAULT_SCOPES): array
    {
        return $this->postToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => config('token_slayer.anthropic.oauth_client_id'),
            'scope' => implode(' ', $scopes),
        ]);
    }

    /**
     * Fetch the account's current usage/quota snapshot.
     *
     * @param  string  $accessToken  a valid OAuth access token
     * @return array<string, mixed> the decoded usage response (usage.json shape)
     *
     * @throws UsageProbeException when the request fails
     */
    public function usage(string $accessToken): array
    {
        return $this->getJson(config('token_slayer.anthropic.usage_endpoint'), $accessToken);
    }

    /**
     * Fetch the account/organization profile associated with an access token.
     *
     * @param  string  $accessToken  a valid OAuth access token
     * @return array<string, mixed> the decoded profile response (profile.json shape)
     *
     * @throws UsageProbeException when the request fails
     */
    public function profile(string $accessToken): array
    {
        return $this->getJson(config('token_slayer.anthropic.profile_endpoint'), $accessToken);
    }

    /**
     * POST a JSON-encoded grant body to the OAuth token endpoint and decode
     * the response, translating failures into a UsageProbeException.
     *
     * @param  array<string, mixed>  $payload  the grant body (authorization_code or refresh_token shape)
     * @return array<string, mixed> the decoded token response
     *
     * @throws UsageProbeException when the grant is rejected or the request fails
     */
    private function postToken(array $payload): array
    {
        try {
            $response = $this->newRequest()
                ->asJson()
                ->post(config('token_slayer.anthropic.token_endpoint'), $payload);
        } catch (ConnectionException $exception) {
            throw new UsageProbeException('connection_failed', 'Unable to reach the Anthropic token endpoint.', $exception);
        }

        return $this->decodeOrFail($response, tokenCall: true);
    }

    /**
     * GET a Bearer-authenticated, beta-headered endpoint and decode the
     * response, translating failures into a UsageProbeException.
     *
     * @param  string  $url  the absolute endpoint URL
     * @param  string  $accessToken  a valid OAuth access token
     * @return array<string, mixed> the decoded JSON response
     *
     * @throws UsageProbeException when the request fails
     */
    private function getJson(string $url, string $accessToken): array
    {
        try {
            $response = $this->newRequest()
                ->withToken($accessToken)
                ->withHeaders(['anthropic-beta' => config('token_slayer.anthropic.beta_header')])
                ->get($url);
        } catch (ConnectionException $exception) {
            throw new UsageProbeException('connection_failed', 'Unable to reach the Anthropic API.', $exception);
        }

        return $this->decodeOrFail($response, tokenCall: false);
    }

    /**
     * Build a pending request pre-configured with the WAF-safe User-Agent
     * every Anthropic request must send.
     *
     * @return PendingRequest
     */
    private function newRequest(): PendingRequest
    {
        return Http::withHeaders([
            'User-Agent' => config('token_slayer.anthropic.user_agent'),
        ]);
    }

    /**
     * Decode a successful response body or throw a UsageProbeException with
     * a machine-readable reason for a failed one. Never includes raw token
     * material in the exception message.
     *
     * @param  Response  $response  the HTTP response to inspect
     * @param  bool  $tokenCall  true for token/refresh grant calls, where 400/401 means an invalid grant
     * @return array<string, mixed> the decoded JSON body
     *
     * @throws UsageProbeException when the response is not a 2xx
     */
    private function decodeOrFail(Response $response, bool $tokenCall): array
    {
        if ($response->successful()) {
            return $response->json() ?? [];
        }

        $status = $response->status();

        if ($status === 429) {
            throw new UsageProbeException('rate_limited', 'Anthropic API rate limit reached.');
        }

        if ($tokenCall && ($status === 400 || $status === 401)) {
            throw new UsageProbeException('invalid_grant', 'Anthropic rejected the OAuth grant.');
        }

        if ($status === 401 || $status === 403) {
            throw new UsageProbeException('unauthorized', 'Anthropic rejected the access token.');
        }

        throw new UsageProbeException('http_error', "Anthropic API request failed with status {$status}.");
    }
}
