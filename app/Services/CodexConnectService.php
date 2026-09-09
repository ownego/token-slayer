<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Exceptions\CodexConnectException;
use App\Models\Account;
use App\Services\Connect\CodexConnectPollResult;
use App\Services\Contracts\AccountDisconnecterContract;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Drives the admin-facing device-code "Connect a Codex account" flow: no
 * loopback listener, no pasted code — the admin opens
 * `https://auth.openai.com/codex/device`, enters the shown `user_code`,
 * approves, while the Filament modal polls {@see poll()} via Livewire
 * `wire:poll` until it reports done or expired. See the
 * codex-oauth-server-side-provisioning research note for the underlying
 * wire protocol this mirrors.
 */
class CodexConnectService implements AccountDisconnecterContract
{
    /**
     * Cache key prefix for a pending device-code attempt, keyed by `state`.
     *
     * @var string
     */
    private const string CACHE_KEY_PREFIX = 'codex-connect:';

    /**
     * The device-code verification URL the admin opens in their browser to
     * enter the user_code.
     *
     * @var string
     */
    private const string VERIFICATION_URL = 'https://auth.openai.com/codex/device';

    /**
     * @param  CodexOAuthClient  $client  the device-code/revoke API client
     * @return void
     */
    public function __construct(
        private readonly CodexOAuthClient $client,
    ) {}

    /**
     * Start a device-code connect attempt: request a fresh user_code, cache
     * its device_auth_id under a random state (TTL matches the code's own
     * expires_at), and return what the Filament modal needs to render.
     *
     * @return array{state: string, user_code: string, verification_url: string}
     *
     * @throws CodexConnectException 'codex_connect_device_code_disabled' when
     *                               the target account/workspace has not
     *                               enabled device-code login (a real,
     *                               documented per-account OpenAI gate, not
     *                               a token-slayer bug)
     */
    public function start(): array
    {
        try {
            $started = $this->client->requestUserCode();
        } catch (RequestException $exception) {
            if ($exception->response->status() === 404) {
                throw new CodexConnectException(
                    'codex_connect_device_code_disabled',
                    'Device-code login is not enabled for this account — a workspace admin must turn it on under Workspace Settings → Permissions (or, for a personal account, ChatGPT Settings → Security → "Allow device code login").',
                    $exception,
                );
            }

            throw $exception;
        }
        $state = Str::random(24);

        Cache::put(
            self::CACHE_KEY_PREFIX.$state,
            ['device_auth_id' => $started['device_auth_id'], 'user_code' => $started['user_code']],
            Carbon::parse($started['expires_at']),
        );

        return [
            'state' => $state,
            'user_code' => $started['user_code'],
            'verification_url' => self::VERIFICATION_URL,
        ];
    }

    /**
     * One poll tick: check whether the human has approved yet. On success,
     * exchanges the authorization code and connects the account via
     * {@see CodexProvisioningService::connectAccount()} (reusing its
     * identity-decoding/upsert logic), then forgets the cached attempt. On
     * expiry (the cached attempt no longer exists — its TTL matched the
     * device code's own expires_at), returns expired rather than throwing,
     * so a UI poll loop can render a friendly message.
     *
     * @param  string  $state  the state returned by {@see start()}
     * @param  string  $name  the admin-facing display name for the account
     * @return CodexConnectPollResult
     */
    public function poll(string $state, string $name): CodexConnectPollResult
    {
        $cacheKey = self::CACHE_KEY_PREFIX.$state;
        $pending = Cache::get($cacheKey);

        if ($pending === null) {
            return CodexConnectPollResult::expired();
        }

        $polled = $this->client->pollDeviceToken($pending['device_auth_id'], $pending['user_code']);

        if ($polled['status'] === 'deviceauth_authorization_pending') {
            return CodexConnectPollResult::pending();
        }

        Cache::forget($cacheKey);

        if ($polled['status'] !== 'success') {
            throw new CodexConnectException('codex_connect_device_code_disabled', 'The device-code login attempt failed: '.$polled['status']);
        }

        $token = $this->client->exchangeAuthorizationCode($polled['authorization_code'], $polled['code_verifier']);

        $authJson = [
            'tokens' => [
                'id_token' => $token['id_token'],
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'],
            ],
            'last_refresh' => now()->toIso8601String(),
            'earliest_refresh_at' => $token['earliest_refresh_at'] ?? null,
        ];

        $account = app(CodexProvisioningService::class)->connectAccount($authJson, $name);

        return CodexConnectPollResult::done($account);
    }

    /**
     * Disconnect a Codex account: revoke the refresh token at OpenAI's
     * revocation endpoint, then wipe the stored tokens and mark the
     * account NeedsReauth — mirrors
     * {@see AccountConnectService::disconnect()}'s semantics, except Codex
     * genuinely revokes server-side (see {@see CodexOAuthClient::revoke()}).
     *
     * @param  Account  $account  the Codex account to disconnect
     * @return void
     */
    public function disconnect(Account $account): void
    {
        $credential = $account->codexCredential;
        if ($credential === null) {
            return;
        }

        if ($credential->codex_refresh_token !== null) {
            $this->client->revoke($credential->codex_refresh_token);
        }

        $credential->codex_access_token = null;
        $credential->codex_refresh_token = null;
        $credential->codex_expires_at = null;
        $credential->status = AccountStatus::NeedsReauth;
        $credential->probe_error = 'disconnected by admin';
        $credential->save();
    }
}
