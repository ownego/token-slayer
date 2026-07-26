<?php

namespace App\Services;

use App\Enums\GrantStatus;
use App\Enums\MembershipStatus;
use App\Exceptions\AccountConnectException;
use App\Models\Account;
use App\Models\AccountProvisionedGrant;
use App\Models\AccountUser;
use App\Models\Device;
use App\Models\User;
use App\Support\CacheKeys;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Provisions a per-device OAuth grant. Durable, non-secret tracking lives on
 * `account_provisioned_grants`; the raw grant itself is held ONLY in the
 * cache, encrypted, with a 24 h TTL — never at rest in the DB long-term, and
 * never on the account's own probe grant.
 */
final class AccountProvisioningService
{
    /**
     * Build the service with the connect flow it delegates the code exchange to.
     *
     * @param  AccountConnectService  $connect  supplies the verifier-pull + code exchange
     * @return void
     */
    public function __construct(private readonly AccountConnectService $connect) {}

    /**
     * The device an admin-driven provision should land on. An explicit id
     * must belong to the user; with no selection a fresh placeholder is
     * created, awaiting its first contact. The legacy `'default'` sentinel
     * is never minted here — it exists only from the backfill migration.
     *
     * @param  User  $user  the user being provisioned
     * @param  int|null  $deviceId  an existing device id, or null for a new placeholder
     * @return Device
     */
    public function resolveProvisionTarget(User $user, ?int $deviceId): Device
    {
        if ($deviceId !== null) {
            return $user->devices()->findOrFail($deviceId);
        }

        return $user->devices()->create(['device_id' => null]);
    }

    /**
     * Exchange a pasted PKCE code and issue a Pending grant to `$device`.
     * Any live grant already on the same (account, device) is revoked first
     * — this enforces the one-live-grant invariant and doubles as the
     * Reissue path. Membership is upserted to Tracked; callers that want a
     * Pending membership (Add member flow) downgrade it afterwards. The raw
     * secret is cached encrypted under the grant's key for 24 h.
     *
     * @param  User  $user  the user being granted access
     * @param  Account  $account  the account to grant
     * @param  Device  $device  the machine this grant is issued to
     * @param  string  $state  the state from {@see AccountConnectService::start()}
     * @param  string  $pastedCode  the `code#state` the admin pasted
     * @return AccountProvisionedGrant the new Pending grant
     *
     * @throws AccountConnectException 'connect_state_expired' | 'connect_no_identity' | 'connect_identity_mismatch' when the pasted code's authorized identity doesn't match `$account`
     */
    public function provisionForDevice(User $user, Account $account, Device $device, string $state, string $pastedCode): AccountProvisionedGrant
    {
        $token = $this->connect->exchangeVerifiedToken($state, $pastedCode, $account);

        $previous = $account->provisionedGrants()->live()->where('device_id', $device->id)->get();
        foreach ($previous as $stale) {
            $this->revoke($stale);
        }

        $grant = $account->provisionedGrants()->create([
            'device_id' => $device->id,
            'status' => GrantStatus::Pending,
            'token_uuid' => $token['token_uuid'] ?? null,
            'provisioned_at' => Carbon::now(),
        ]);

        $user->accounts()->syncWithoutDetaching([
            $account->id => ['status' => MembershipStatus::Tracked->value],
        ]);

        $payload = [
            'name' => $account->email,
            'email' => $account->email,
            'org_uuid' => $account->organization_uuid,
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'],
            'expires_at' => Carbon::now()->addSeconds((int) $token['expires_in'])->timestamp,
        ];
        Cache::put(
            CacheKeys::provisionedGrant($grant->id),
            Crypt::encryptString(json_encode($payload)),
            CacheKeys::PROVISIONED_GRANT_TTL_SECONDS,
        );

        return $grant;
    }

    /**
     * Read and return every provisioned grant for a user whose encrypted cache
     * secret is still present, decrypted into its payload. This is idempotent
     * within the secret's 24 h TTL: the cache is NOT consumed, so re-running
     * setup returns the same grants until the secret expires or the provision
     * is revoked (which forgets the secret). The first successful read records
     * {@see AccountUser::claimed_at}; later reads leave it unchanged. Rows whose
     * cache entry is gone are skipped.
     *
     * @param  User  $user  the hook-authenticated user pulling grants
     * @return array<int, array<string, mixed>> the decoded grant payloads
     */
    public function claim(User $user): array
    {
        $payloads = [];

        foreach ($this->claimableFor($user) as $pivot) {
            $key = $this->cacheKey($pivot->user_id, $pivot->account_id);
            $raw = Cache::get($key);
            if ($raw === null) {
                continue; // cache secret expired/revoked — nothing to hand off
            }

            $payloads[] = json_decode(Crypt::decryptString($raw), true);
            if ($pivot->claimed_at === null) {
                $pivot->forceFill(['claimed_at' => Carbon::now()])->save();
            }
        }

        return $payloads;
    }

    /**
     * Confirm the CLI's reconcile: promote each `set_up` org Pending→Tracked, and
     * stamp `deprovisioned_at` on each `removed` org. Additive only; never
     * demotes/deletes. Uuids are deduped per list.
     *
     * Security scope differs per loop:
     * - `set_up` (promotion) is a privilege change, so it stays behind the strict
     *   self-graft guard — an org is only promoted if the user already holds a
     *   provisioned pivot for it (`account_user.provisioned_at` set and
     *   `revoked_at` null); see {@see Account::provisionedUsers()} and
     *   {@see guardedProvisionedAccount()}. Without that check a hook-token
     *   holder could self-graft membership onto any org uuid it sends; an org
     *   the user was never provisioned for is skipped exactly like an unknown
     *   org (not created, not counted).
     * - `removed` (stamping `deprovisioned_at`) is intentionally NOT behind that
     *   guard: the update is scoped to `WHERE user_id = $user->id`, grants no
     *   access, and only suppresses the caller's OWN future `remove`
     *   instruction (see {@see removable()}), so there is no self-graft risk.
     *   This also lets event-materialized pivots (`provisioned_at` null) that
     *   {@see removable()} deliberately surfaces self-clear once confirmed —
     *   gating them on the provisioned guard would make them reappear in
     *   `remove` on every reconcile forever.
     *
     * A failure writing one org's pivot is reported and swallowed so it
     * can't 500 the rest of the batch. Additive only: orgs absent from
     * both lists are untouched, and nothing here is ever demoted, revoked,
     * or deleted. Incoming uuids are deduped so a repeated uuid can't
     * double-count.
     *
     * Deliberately does NOT call {@see CacheKeys::forgetAccountMembership()}
     * (owner decision): that 1 h aggregate cache only feeds the Events/Last-seen
     * columns, the status badge reads `pivot.status` live, and the tab's
     * Refresh action already clears it on demand.
     *
     * @param  User  $user  the hook-authenticated user
     * @param  array<int, string>  $setUpOrgUuids  orgs the CLI finished setting up
     * @param  array<int, string>  $removedOrgUuids  orgs the CLI removed the local slot for
     * @return array{confirmed: int, deprovisioned: int}
     */
    public function confirmSetup(User $user, array $setUpOrgUuids, array $removedOrgUuids = []): array
    {
        $confirmed = 0;
        foreach (array_unique($setUpOrgUuids) as $orgUuid) {
            $account = $this->guardedProvisionedAccount($user, $orgUuid);
            if ($account === null) {
                continue;
            }
            try {
                $account->users()->syncWithoutDetaching([
                    $user->id => ['status' => MembershipStatus::Tracked->value],
                ]);
                $pivot = AccountUser::query()
                    ->where('user_id', $user->id)->where('account_id', $account->id)->first();
                if ($pivot !== null && $pivot->claimed_at === null) {
                    $pivot->forceFill(['claimed_at' => Carbon::now()])->save();
                }
                $confirmed++;
            } catch (Throwable $e) {
                report($e);

                continue;
            }
        }

        $deprovisioned = 0;
        foreach (array_unique($removedOrgUuids) as $orgUuid) {
            $account = Account::query()->where('organization_uuid', $orgUuid)->first();
            if ($account === null) {
                continue; // unknown org — never create one from client input
            }
            try {
                $affected = AccountUser::query()
                    ->where('user_id', $user->id)->where('account_id', $account->id)
                    ->update(['deprovisioned_at' => Carbon::now()]);
                if ($affected > 0) {
                    $deprovisioned++;
                }
            } catch (Throwable $e) {
                report($e);

                continue;
            }
        }

        return ['confirmed' => $confirmed, 'deprovisioned' => $deprovisioned];
    }

    /**
     * Resolve the `Account` for `$orgUuid` only if `$user` holds a non-revoked
     * provisioned pivot for it — the self-graft guard for the `set_up`
     * (promotion) loop in {@see confirmSetup()}. Returns null for an unknown
     * org or one the user was never provisioned for.
     *
     * @param  User  $user  the hook-authenticated user
     * @param  string  $orgUuid  organization uuid from the client
     * @return Account|null
     */
    private function guardedProvisionedAccount(User $user, string $orgUuid): ?Account
    {
        $account = Account::query()->where('organization_uuid', $orgUuid)->first();
        if ($account === null) {
            return null;
        }
        $isProvisionedForUser = $account->provisionedUsers()
            ->wherePivot('user_id', $user->id)
            ->wherePivotNull('revoked_at')
            ->exists();

        return $isProvisionedForUser ? $account : null;
    }

    /**
     * Soft-revoke a grant: mark it Revoked and forget the cached secret so
     * a future claim cannot re-serve it. (A grant already handed to a
     * client must be deleted separately at claude.ai using its token_uuid.)
     *
     * @param  AccountProvisionedGrant  $grant  the grant to revoke
     * @return void
     */
    public function revoke(AccountProvisionedGrant $grant): void
    {
        $grant->forceFill(['status' => GrantStatus::Revoked, 'revoked_at' => Carbon::now()])->save();
        CacheKeys::forgetProvisionedGrant($grant->id);
    }

    /**
     * The user's verified (Tracked) org memberships, as `[['org_uuid' => ...]]`.
     * NOT filtered by `provisioned_at` — a member can be verified without ever
     * being provisioned (an admin "verify" on an event contributor). Used by the
     * client to prioritize which account to make active when the current one is
     * being removed.
     *
     * @param  User  $user  the hook-authenticated user
     * @return array<int, array{org_uuid: string}>
     */
    public function memberships(User $user): array
    {
        return $user->accounts()
            ->wherePivot('status', MembershipStatus::Tracked->value)
            ->whereNotNull('organization_uuid')
            ->pluck('organization_uuid')
            ->map(fn (string $org): array => ['org_uuid' => $org])
            ->all();
    }

    /**
     * The org accounts the user is no longer Tracked on and that the client has
     * not yet confirmed removing, as `[['org_uuid' => ...]]`. Selector:
     * `status=Untracked AND deprovisioned_at IS NULL AND organization_uuid NOT NULL`.
     * Deliberately NOT filtered by `provisioned_at`: any Untracked org account
     * should leave the user's machine; event-materialized rows self-clear once
     * the client confirms (best-effort no-op) and `deprovisioned_at` is stamped.
     *
     * @param  User  $user  the hook-authenticated user
     * @return array<int, array{org_uuid: string}>
     */
    public function removable(User $user): array
    {
        return $user->accounts()
            ->wherePivot('status', MembershipStatus::Untracked->value)
            ->wherePivotNull('deprovisioned_at')
            ->whereNotNull('organization_uuid')
            ->pluck('organization_uuid')
            ->map(fn (string $org): array => ['org_uuid' => $org])
            ->all();
    }
}
