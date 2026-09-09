<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\GrantStatus;
use App\Enums\MembershipStatus;
use App\Exceptions\CodexConnectException;
use App\Models\Account;
use App\Models\AccountProvisionedGrant;
use App\Models\CodexCredential;
use App\Models\User;
use App\Support\CacheKeys;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Admin-side Codex account provisioning: Step A ({@see connectAccount()})
 * populates a shared Codex account's own persistent credential; Step B
 * ({@see provisionForDevice()}) issues a per-device grant, cached the same
 * way {@see AccountProvisioningService} caches Claude's. Standalone from
 * `AccountProvisioningService` — never touches its Claude-specific
 * `claim()`/`removable()`/`confirmSetup()`/`memberships()` methods (those
 * stay Claude-only; see the spec's §7 correction for why).
 */
final class CodexProvisioningService
{
    /**
     * @param  AccountProvisioningService  $accounts  supplies provider-agnostic device resolution
     * @param  CodexOAuthClient  $oauth  the OpenAI revoke-endpoint client
     * @return void
     */
    public function __construct(
        private readonly AccountProvisioningService $accounts,
        private readonly CodexOAuthClient $oauth,
    ) {}

    /**
     * Step A: connect a shared Codex account. Upserts by the stable
     * `chatgpt_account_id` (never by `$name`, a mutable display label) so
     * re-running this for the same account updates in place.
     *
     * @param  array<string, mixed>  $authJson  the uploaded auth.json content
     * @param  string  $name  an admin-facing display name for the account
     * @return Account
     *
     * @throws CodexConnectException when the auth.json's id_token carries no chatgpt_account_id
     */
    public function connectAccount(array $authJson, string $name): Account
    {
        $chatgptAccountId = $this->identity($authJson, 'chatgpt_account_id');
        if ($chatgptAccountId === null) {
            throw new CodexConnectException('missing chatgpt_account_id in the uploaded auth.json');
        }

        $credential = CodexCredential::query()->where('chatgpt_account_id', $chatgptAccountId)->first();
        $account = $credential?->account ?? new Account;
        $account->fill([
            'name' => $name,
            'email' => $this->identity($authJson, 'email', namespaced: false),
            'provider' => 'codex',
        ]);
        $account->save();

        $credential = $credential ?? new CodexCredential(['account_id' => $account->id]);
        $credential->fill([
            'account_id' => $account->id,
            'chatgpt_account_id' => $chatgptAccountId,
            'chatgpt_user_id' => $this->identity($authJson, 'chatgpt_user_id'),
            'plan_type' => $this->identity($authJson, 'chatgpt_plan_type'),
            'codex_access_token' => $authJson['tokens']['access_token'] ?? null,
            'codex_refresh_token' => $authJson['tokens']['refresh_token'] ?? null,
            'codex_expires_at' => $this->accessTokenExpiry($authJson),
            'last_refreshed_at' => $this->lastRefresh($authJson),
            'status' => AccountStatus::Active,
        ]);
        $credential->save();

        return $account;
    }

    /**
     * Step B: issue a per-device grant. Confirms the uploaded auth.json's
     * identity matches `$account` (self-graft guard), revokes any live
     * grant already on the resolved device (one-live-grant invariant,
     * mirrors `AccountProvisioningService::provisionForDevice()`), and
     * caches the FULL raw auth.json — the employee-side pull writes it back
     * verbatim, per `CodexCredential.auth_json`'s client-side contract.
     * Unlike Claude's flow, membership syncs to Tracked immediately here —
     * there is no separate confirm-setup step for Codex in this phase.
     *
     * @param  Account  $account  the target Codex account (already connected via Step A)
     * @param  User  $user  the employee being provisioned
     * @param  array<string, mixed>  $authJson  the uploaded auth.json content, for a fresh login into `$account`
     * @return AccountProvisionedGrant the new Pending grant
     *
     * @throws CodexConnectException when the auth.json's identity doesn't match `$account`
     */
    public function provisionForDevice(Account $account, User $user, array $authJson): AccountProvisionedGrant
    {
        $chatgptAccountId = $this->identity($authJson, 'chatgpt_account_id');
        if ($chatgptAccountId === null || $chatgptAccountId !== $account->codexCredential?->chatgpt_account_id) {
            throw new CodexConnectException('the uploaded auth.json does not match the target account');
        }

        $device = $this->accounts->resolveProvisionTarget($user, null);

        foreach ($account->provisionedGrants()->live()->where('device_id', $device->id)->get() as $stale) {
            $this->revoke($stale);
        }

        $grant = $account->provisionedGrants()->create([
            'device_id' => $device->id,
            'status' => GrantStatus::Pending,
            'provisioned_at' => Carbon::now(),
        ]);

        $user->accounts()->syncWithoutDetaching([
            $account->id => ['status' => MembershipStatus::Tracked->value],
        ]);

        $payload = [
            'provider' => 'codex',
            'name' => $account->name ?? $account->email,
            'email' => $this->identity($authJson, 'email', namespaced: false),
            'chatgpt_account_id' => $chatgptAccountId,
            'auth_json' => $authJson,
        ];
        Cache::put(
            CacheKeys::provisionedGrant($grant->id),
            Crypt::encryptString(json_encode($payload)),
            CacheKeys::PROVISIONED_GRANT_TTL_SECONDS,
        );

        return $grant;
    }

    /**
     * Soft-revoke a grant: calls the real OpenAI revoke endpoint with the
     * cached refresh token first (a genuine capability Claude doesn't
     * have), then marks the row Revoked and forgets the cached secret —
     * mirrors `AccountProvisioningService::revoke()`'s local half exactly.
     *
     * @param  AccountProvisionedGrant  $grant  the grant to revoke
     * @return void
     */
    public function revoke(AccountProvisionedGrant $grant): void
    {
        $refreshToken = $this->cachedRefreshToken($grant);
        if ($refreshToken !== null) {
            $this->oauth->revoke($refreshToken);
        }

        $grant->forceFill(['status' => GrantStatus::Revoked, 'revoked_at' => Carbon::now()])->save();
        CacheKeys::forgetProvisionedGrant($grant->id);
    }

    /**
     * Read the cached refresh token for a still-live grant, or null once
     * the cache secret has already expired/been forgotten.
     *
     * @param  AccountProvisionedGrant  $grant  the grant being revoked
     * @return string|null
     */
    private function cachedRefreshToken(AccountProvisionedGrant $grant): ?string
    {
        $raw = Cache::get(CacheKeys::provisionedGrant($grant->id));
        if ($raw === null) {
            return null;
        }
        $payload = json_decode(Crypt::decryptString($raw), true);

        return $payload['auth_json']['tokens']['refresh_token'] ?? null;
    }

    /**
     * Decode the access_token JWT's own `exp` claim — auth.json (as written
     * by the `codex` CLI binary) carries no `expires_in`/OAuth-response
     * envelope, so this is the only real expiry signal available.
     *
     * @param  array<string, mixed>  $authJson  the uploaded auth.json content
     * @return Carbon|null
     */
    private function accessTokenExpiry(array $authJson): ?Carbon
    {
        $exp = $this->decodeJwtClaim($authJson['tokens']['access_token'] ?? '', 'exp');

        return is_int($exp) ? Carbon::createFromTimestamp($exp) : null;
    }

    /**
     * Parse auth.json's own `last_refresh` timestamp (ISO 8601).
     *
     * @param  array<string, mixed>  $authJson  the uploaded auth.json content
     * @return Carbon|null
     */
    private function lastRefresh(array $authJson): ?Carbon
    {
        $raw = $authJson['last_refresh'] ?? null;

        return is_string($raw) ? Carbon::parse($raw) : null;
    }

    /**
     * Decode one identity claim from auth.json's id_token — never trusts a
     * client-supplied identity field directly, mirrors `AccountResolver`'s
     * "never creates one from client input" posture.
     *
     * @param  array<string, mixed>  $authJson  the uploaded auth.json content
     * @param  string  $claim  the claim name to read
     * @param  bool  $namespaced  true for claims under the `https://api.openai.com/auth` namespace (the default), false for a top-level OIDC claim like `email`
     * @return string|null
     */
    private function identity(array $authJson, string $claim, bool $namespaced = true): ?string
    {
        $idToken = $authJson['tokens']['id_token'] ?? '';
        $value = $namespaced
            ? $this->decodeJwtClaim($idToken, $claim, 'https://api.openai.com/auth')
            : $this->decodeJwtClaim($idToken, $claim);

        return is_string($value) ? $value : null;
    }

    /**
     * Decode a claim from a JWT's payload segment without verifying its
     * signature — this repo never trusts the JWT for AUTHORIZATION (the
     * bearer token that gated the upload already established the caller is
     * an admin); it only reads DECLARED identity out of it, the same trust
     * boundary `token-slayer-cli`'s own JWT decode helpers operate under.
     *
     * @param  string  $jwt  the encoded JWT
     * @param  string  $claim  the claim name to read
     * @param  string|null  $namespace  a namespace key to look under, or null for a top-level claim
     * @return mixed
     */
    private function decodeJwtClaim(string $jwt, string $claim, ?string $namespace = null): mixed
    {
        $segments = explode('.', $jwt);
        if (count($segments) < 2) {
            return null;
        }
        $payload = json_decode($this->base64UrlDecode($segments[1]), true);
        if (! is_array($payload)) {
            return null;
        }

        return $namespace !== null ? ($payload[$namespace][$claim] ?? null) : ($payload[$claim] ?? null);
    }

    /**
     * Decode a base64url-encoded JWT segment, padding it back to a valid
     * base64 length first.
     *
     * @param  string  $data  the base64url-encoded segment
     * @return string
     */
    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode(strtr($data, '-_', '+/'));
    }
}
