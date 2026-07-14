<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Exceptions\AccountConnectException;
use App\Models\Account;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Drives the admin-facing PKCE "Connect" flow that grants token-slayer a
 * server-side OAuth token for an org `Account`: {@see start()} builds the
 * authorize URL the admin opens in their browser, and {@see complete()}
 * exchanges the code they paste back for tokens, guarded by an
 * email-match check so an admin cannot accidentally attach a stranger's
 * Claude account to the wrong org account row.
 *
 * Per token-hygiene requirements, raw token material is never logged,
 * exposed in exception messages, or written to `probe_error`.
 */
class AccountConnectService
{
    /**
     * Cache key prefix for a pending connect attempt, keyed by `state`. The
     * full key is `{PREFIX}{state}`.
     *
     * @var string
     */
    private const string CACHE_KEY_PREFIX = 'account-connect:';

    /**
     * How long a pending connect attempt's PKCE verifier stays cached
     * before it is considered expired. The entry is also single-use: it is
     * forgotten as soon as {@see complete()} reads it, whether or not the
     * exchange that follows succeeds.
     *
     * @var int
     */
    private const int CACHE_TTL_MINUTES = 10;

    /**
     * Base URL of Anthropic's PKCE authorize endpoint, verified live from
     * the Claude Code binary (2026-07-10). Do not substitute the older
     * claude.ai host.
     *
     * @var string
     */
    private const string AUTHORIZE_URL = 'https://claude.com/cai/oauth/authorize';

    /**
     * OAuth scopes requested by the connect flow's authorize URL, verified
     * live from the Claude Code binary (2026-07-10).
     *
     * @var array<int, string>
     */
    private const array SCOPES = [
        'org:create_api_key',
        'user:profile',
        'user:inference',
        'user:sessions:claude_code',
        'user:mcp_servers',
        'user:file_upload',
    ];

    /**
     * Build the service with the OAuth client and prober it delegates to.
     *
     * @param  AnthropicOAuthClient  $client  the token/usage/profile API client
     * @param  UsageProber  $prober  the quota prober invoked best-effort after a successful connect
     * @return void
     */
    public function __construct(
        private readonly AnthropicOAuthClient $client,
        private readonly UsageProber $prober,
    ) {}

    /**
     * Start a PKCE connect attempt for an account: generate a verifier,
     * S256 challenge, and random state; cache the verifier and account id
     * under the state key for CACHE_TTL_MINUTES (single-use — invalidated
     * the moment {@see complete()} reads it); and build the authorize URL
     * the admin opens in their browser.
     *
     * @param  Account  $account  the org account being connected
     * @return array{url: string, state: string} the authorize URL and the state to echo back on completion
     */
    public function start(Account $account): array
    {
        $verifier = $this->generateVerifier();
        $state = $this->generateState();

        Cache::put(
            self::CACHE_KEY_PREFIX.$state,
            ['verifier' => $verifier, 'account_id' => $account->id],
            now()->addMinutes(self::CACHE_TTL_MINUTES),
        );

        $url = self::AUTHORIZE_URL.'?'.http_build_query([
            'code' => 'true',
            'client_id' => config('token_slayer.anthropic.oauth_client_id'),
            'response_type' => 'code',
            'redirect_uri' => config('token_slayer.anthropic.redirect_uri'),
            'scope' => implode(' ', self::SCOPES),
            'code_challenge' => $this->challengeFor($verifier),
            'code_challenge_method' => 'S256',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        return ['url' => $url, 'state' => $state];
    }

    /**
     * Complete a PKCE connect attempt: pull and single-use-invalidate the
     * cached verifier, exchange the pasted code for tokens, and enforce
     * that the newly-authorized Claude account's email matches this org
     * account's email before storing anything. On a match, stores the
     * tokens, identity, and plan, flips the account to Active, and
     * best-effort probes it immediately.
     *
     * @param  string  $state  the state value returned by {@see start()}
     * @param  string  $pastedCode  the code the admin pasted back, either `CODE` or `CODE#STATE`
     * @return Account the connected account, freshly persisted
     *
     * @throws AccountConnectException when the state is missing/expired/already used ('connect_state_expired')
     *                                 or the authorized account's email does not match ('connect_email_mismatch')
     */
    public function complete(string $state, string $pastedCode): Account
    {
        $cacheKey = self::CACHE_KEY_PREFIX.$state;
        $pending = Cache::get($cacheKey);
        Cache::forget($cacheKey);

        if ($pending === null) {
            throw new AccountConnectException('connect_state_expired', 'This connect link expired or was already used. Start again.');
        }

        $account = Account::query()->find($pending['account_id']);
        if ($account === null) {
            throw new AccountConnectException('connect_state_expired', 'The account being connected no longer exists.');
        }

        $code = Str::before($pastedCode, '#');

        $token = $this->client->exchangeCode($code, $pending['verifier'], $state);
        $profile = $this->client->profile($token['access_token']);

        $profileEmail = $profile['account']['email'] ?? null;
        if ($profileEmail === null || mb_strtolower($profileEmail) !== mb_strtolower($account->email)) {
            throw new AccountConnectException('connect_email_mismatch', 'The Claude account you authorized does not match this org account\'s email.');
        }

        $account->oauth_access_token = $token['access_token'];
        $account->oauth_refresh_token = $token['refresh_token'];
        $account->oauth_expires_at = now()->addSeconds($token['expires_in']);
        $account->account_uuid = $profile['account']['uuid'] ?? ($token['account']['uuid'] ?? null);
        $account->plan = $profile['organization']['rate_limit_tier'] ?? $account->plan;
        $account->status = AccountStatus::Active;
        $account->probe_error = null;
        $account->save();

        $this->learnOrganizationUuid($account, $profile['organization']['uuid'] ?? null);

        $this->probeBestEffort($account);

        return $account->refresh();
    }

    /**
     * Sever the stored OAuth grant for an account — the compromised-token
     * response. Anthropic exposes NO token revocation endpoint (verified:
     * `POST /v1/oauth/revoke` → 404), so there is no server-side revoke to
     * attempt; this wipes the locally stored access/refresh tokens and expiry
     * and marks the account `NeedsReauth`. The real kill switch remains with
     * the account owner (claude.ai → revoke app access / sign out all
     * sessions), surfaced to the admin in the action's confirm runbook.
     *
     * @param  Account  $account  the account to disconnect
     * @return void
     */
    public function disconnect(Account $account): void
    {
        $account->oauth_access_token = null;
        $account->oauth_refresh_token = null;
        $account->oauth_expires_at = null;
        $account->status = AccountStatus::NeedsReauth;
        $account->probe_error = 'disconnected by admin';
        $account->save();
    }

    /**
     * Generate a PKCE code verifier: base64url(random 32 bytes), no padding.
     *
     * @return string the code verifier
     */
    private function generateVerifier(): string
    {
        return $this->base64UrlEncode(random_bytes(32));
    }

    /**
     * Derive the S256 code challenge for a verifier: base64url(sha256(verifier)), no padding.
     *
     * @param  string  $verifier  the PKCE code verifier
     * @return string the code challenge
     */
    private function challengeFor(string $verifier): string
    {
        return $this->base64UrlEncode(hash('sha256', $verifier, binary: true));
    }

    /**
     * Generate a random opaque state value for a connect attempt.
     *
     * @return string the state value
     */
    private function generateState(): string
    {
        return $this->base64UrlEncode(random_bytes(24));
    }

    /**
     * Base64url-encode (unpadded) a raw byte string.
     *
     * @param  string  $bytes  the raw bytes to encode
     * @return string the base64url-encoded, unpadded string
     */
    private function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Attempt to record the newly-connected account's organization uuid,
     * respecting the `organization_uuid` unique constraint. Mirrors
     * {@see AccountResolver}'s learn-uuid pattern: on a collision (another
     * account already claims this uuid), the write is skipped and logged
     * rather than failing the whole connect.
     *
     * @param  Account  $account  the just-connected account
     * @param  ?string  $organizationUuid  the organization uuid from the profile response, if any
     * @return void
     */
    private function learnOrganizationUuid(Account $account, ?string $organizationUuid): void
    {
        if ($organizationUuid === null || $account->organization_uuid === $organizationUuid) {
            return;
        }

        $account->organization_uuid = $organizationUuid;

        try {
            $account->save();
        } catch (QueryException) {
            // Another account row already claims this organization uuid
            // (unique constraint). Skip the write rather than failing the
            // whole connect; the admin can reconcile the duplicate later.
            $account->organization_uuid = $account->getOriginal('organization_uuid');

            Log::warning('Skipped writing organization_uuid after a connect: unique constraint collision.', [
                'account_id' => $account->id,
                'attempted_organization_uuid' => $organizationUuid,
            ]);
        }
    }

    /**
     * Probe the just-connected account's quota immediately so the admin
     * sees a fresh utilization snapshot without waiting for the next
     * scheduled cycle. A probe failure must never fail the connect.
     *
     * @param  Account  $account  the just-connected account
     * @return void
     */
    private function probeBestEffort(Account $account): void
    {
        try {
            $this->prober->probe($account);
        } catch (Throwable) {
            // Best-effort only: the connect already succeeded, and the
            // prober's own 5-minute cycle will retry.
        }
    }
}
