<?php

namespace App\Services;

use App\Enums\GrantStatus;
use App\Enums\MembershipStatus;
use App\Exceptions\AccountConnectException;
use App\Models\Account;
use App\Models\AccountProvisionedGrant;
use App\Models\Device;
use App\Models\User;
use App\Services\Provisioning\DeviceClaimResolver;
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
     * Build the service with the connect flow and the device resolver.
     *
     * @param  AccountConnectService  $connect  supplies the verifier-pull + code exchange
     * @param  DeviceClaimResolver  $resolver  maps a claim fingerprint to a device
     * @return void
     */
    public function __construct(
        private readonly AccountConnectService $connect,
        private readonly DeviceClaimResolver $resolver,
    ) {}

    /**
     * The device an admin-driven provision should land on. An explicit id
     * must belong to the user; with no selection a fresh placeholder is
     * created, awaiting its first contact. The legacy `'default'` sentinel
     * is never minted here — it exists only from the backfill migration.
     * `$name` is only applied when a new placeholder is created; it is
     * ignored when an existing device is targeted by id.
     *
     * @param  User  $user  the user being provisioned
     * @param  int|null  $deviceId  an existing device id, or null for a new placeholder
     * @param  string|null  $name  an admin-facing label for the new placeholder, if any
     * @return Device
     */
    public function resolveProvisionTarget(User $user, ?int $deviceId, ?string $name = null): Device
    {
        if ($deviceId !== null) {
            return $user->devices()->findOrFail($deviceId);
        }

        return $user->devices()->create(['device_id' => null, 'name' => $name]);
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
     * Resolve the calling machine's device (spec §3) and serve every
     * non-revoked, non-deprovisioned grant on it whose encrypted cache
     * secret is still alive. A Pending grant is marked Claimed on first
     * serve; the secret is NOT consumed, so re-running setup stays
     * idempotent for the 24 h TTL. The `deprovisioned_at` exclusion is a
     * belt for legacy rows stamped before {@see confirmSetup()} started
     * revoking on confirm — those rows can still be Claimed with a live
     * cache secret, and must never be re-served.
     *
     * @param  User  $user  the hook-authenticated user pulling grants
     * @param  string|null  $fingerprint  the client device fingerprint; null = old CLI
     * @return array<int, array<string, mixed>> the decoded grant payloads
     */
    public function claim(User $user, ?string $fingerprint): array
    {
        $device = $this->resolver->resolve($user, $fingerprint);
        if ($device === null) {
            return [];
        }

        $payloads = [];
        foreach ($device->grants()->live()->whereNull('deprovisioned_at')->get() as $grant) {
            $raw = Cache::get(CacheKeys::provisionedGrant($grant->id));
            if ($raw === null) {
                continue; // cache secret expired/revoked — nothing to hand off
            }

            $payloads[] = json_decode(Crypt::decryptString($raw), true);
            if ($grant->status === GrantStatus::Pending) {
                $grant->forceFill(['status' => GrantStatus::Claimed, 'claimed_at' => Carbon::now()])->save();
            }
        }

        return $payloads;
    }

    /**
     * The org accounts this request should be told to remove: the user's
     * Untracked orgs minus those the resolved device already confirmed via
     * its NEWEST grant per account (its `deprovisioned_at` stamp). Only the
     * newest grant counts — an older, revoked grant (left behind by a
     * Reissue) can carry a stale stamp from before the account was
     * re-provisioned onto the same device, which must not silence a fresh
     * removal instruction. Per-device on purpose — with the old per-user
     * stamp, the first machine to confirm silenced the instruction for
     * every other machine, which then kept the slot forever. When this
     * request resolved no device (e.g. an unrecognized fingerprint), every
     * one of the user's Untracked orgs is returned unconditionally — the
     * client-side CLI safely no-ops a removal for a slot it doesn't have,
     * and the broadcast self-terminates once the admin verifies or
     * provisions the user.
     *
     * @param  User  $user  the hook-authenticated user
     * @param  Device|null  $device  the resolved claiming device; null = this request resolved no device
     * @return array<int, array{org_uuid: string}>
     */
    public function removable(User $user, ?Device $device): array
    {
        $confirmedAccountIds = $device === null
            ? collect()
            : $device->grants()
                ->orderByDesc('id')
                ->get()
                ->unique('account_id')
                ->whereNotNull('deprovisioned_at')
                ->pluck('account_id');

        return $user->accounts()
            ->wherePivot('status', MembershipStatus::Untracked->value)
            ->whereNotNull('organization_uuid')
            ->whereKeyNot($confirmedAccountIds)
            ->pluck('organization_uuid')
            ->map(fn (string $org): array => ['org_uuid' => $org])
            ->all();
    }

    /**
     * Confirm the CLI's reconcile: promote each `set_up` org Pending→Tracked
     * behind the live-grant guard, and for each `removed` org, kill THIS
     * device's newest grant — revoked (status Revoked, `revoked_at` set,
     * cached secret forgotten) AND stamped `deprovisioned_at` (creating a
     * Revoked, already-deprovisioned tombstone row when the device holds no
     * grant for the org, so event-materialized removals self-clear per
     * device) — but only when `$user` holds an `account_user` row for that
     * account (any status); otherwise the org is skipped, so a hook-token
     * holder cannot plant a tombstone on an account they aren't a member of
     * just by knowing its org uuid. A confirmed removal is terminal for that
     * grant: it can never be re-served by {@see claim()}, even within its
     * cache secret's 24 h TTL — a second claim before the fix would re-serve
     * the still-Claimed, still-cached grant and the CLI would silently
     * re-add the slot it had just deleted. Additive only; failures on one
     * org are reported and swallowed so they cannot 500 the batch; uuids
     * are deduped per list.
     *
     * @param  User  $user  the hook-authenticated user
     * @param  array<int, string>  $setUpOrgUuids  orgs the CLI finished setting up
     * @param  array<int, string>  $removedOrgUuids  orgs the CLI removed the local slot for
     * @param  Device|null  $device  the resolved claiming device; null = removed loop no-ops
     * @return array{confirmed: int, deprovisioned: int}
     */
    public function confirmSetup(User $user, array $setUpOrgUuids, array $removedOrgUuids = [], ?Device $device = null): array
    {
        $confirmed = 0;
        foreach (array_unique($setUpOrgUuids) as $orgUuid) {
            $account = $this->accountWithLiveGrantFor($user, $orgUuid);
            if ($account === null) {
                continue;
            }
            try {
                $account->users()->syncWithoutDetaching([
                    $user->id => ['status' => MembershipStatus::Tracked->value],
                ]);
                $confirmed++;
            } catch (Throwable $e) {
                report($e);

                continue;
            }
        }

        $deprovisioned = 0;
        if ($device !== null) {
            foreach (array_unique($removedOrgUuids) as $orgUuid) {
                $account = Account::query()->where('organization_uuid', $orgUuid)->first();
                if ($account === null) {
                    continue; // unknown org — never create one from client input
                }
                $isMember = $user->accounts()->whereKey($account->id)->exists();
                if (! $isMember) {
                    continue; // no membership row (any status) — never let a hook-token holder plant a tombstone by uuid alone
                }
                try {
                    $grant = $device->grants()->where('account_id', $account->id)->latest('id')->first();
                    if ($grant !== null) {
                        $grant->forceFill([
                            'status' => GrantStatus::Revoked,
                            'revoked_at' => Carbon::now(),
                            'deprovisioned_at' => Carbon::now(),
                        ])->save();
                        CacheKeys::forgetProvisionedGrant($grant->id);
                    } else {
                        $device->grants()->create([
                            'account_id' => $account->id,
                            'status' => GrantStatus::Revoked,
                            'provisioned_at' => Carbon::now(),
                            'revoked_at' => Carbon::now(),
                            'deprovisioned_at' => Carbon::now(),
                        ]);
                    }
                    $deprovisioned++;
                } catch (Throwable $e) {
                    report($e);

                    continue;
                }
            }
        }

        return ['confirmed' => $confirmed, 'deprovisioned' => $deprovisioned];
    }

    /**
     * Resolve the `Account` for `$orgUuid` only if `$user` holds a live
     * (non-revoked) grant for it on any of their devices — the self-graft
     * guard for the `set_up` promotion loop.
     *
     * @param  User  $user  the hook-authenticated user
     * @param  string  $orgUuid  organization uuid from the client
     * @return Account|null
     */
    private function accountWithLiveGrantFor(User $user, string $orgUuid): ?Account
    {
        $account = Account::query()->where('organization_uuid', $orgUuid)->first();
        if ($account === null) {
            return null;
        }

        $isGranted = $account->provisionedGrants()->live()
            ->whereHas('device', fn ($query) => $query->where('user_id', $user->id))
            ->exists();

        return $isGranted ? $account : null;
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
}
