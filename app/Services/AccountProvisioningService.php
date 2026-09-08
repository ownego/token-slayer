<?php

namespace App\Services;

use App\Enums\GrantStatus;
use App\Enums\MembershipStatus;
use App\Exceptions\AccountConnectException;
use App\Http\Controllers\Api\ProvisionedAccountController;
use App\Models\Account;
use App\Models\AccountProvisionedGrant;
use App\Models\Device;
use App\Models\User;
use App\Services\Contracts\GrantRevokerContract;
use App\Services\Provisioning\DeviceClaimResolver;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Provisions a per-device OAuth grant. Durable, non-secret tracking AND the
 * raw grant secret itself both live on `account_provisioned_grants` (the
 * `pending_claude_*` columns, `encrypted` casts) — never on the account's
 * own probe grant, and never in a TTL-bound cache.
 */
final class AccountProvisioningService implements GrantRevokerContract
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
     * secret is written straight onto the grant row (`pending_claude_*`,
     * `encrypted` casts) — durable, no TTL.
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
            'pending_claude_access_token' => $token['access_token'],
            'pending_claude_refresh_token' => $token['refresh_token'],
            'pending_claude_expires_at' => Carbon::now()->addSeconds((int) $token['expires_in']),
        ]);

        $user->accounts()->syncWithoutDetaching([
            $account->id => ['status' => MembershipStatus::Tracked->value],
        ]);

        return $grant;
    }

    /**
     * Resolve the calling machine's device (spec §3) and serve every
     * non-revoked, non-deprovisioned grant on it whose durable secret field
     * is still set AND whose account the user is not Untracked on. A Pending
     * grant is marked Claimed on first serve; the secret field is NOT
     * cleared here, so re-running setup stays idempotent until
     * {@see ProvisionedAccountController::confirm()}
     * reports success (see {@see confirmSetup()}). The `deprovisioned_at`
     * exclusion is a belt for legacy rows stamped before {@see confirmSetup()}
     * started revoking on confirm — those rows can still carry a live
     * secret field, and must never be re-served. The Untracked exclusion
     * closes the window between an admin Unverify and the device's first
     * confirm: without it, a claim response could hand back an org's
     * credential while {@see removable()} orders the same org removed, and
     * the CLI would plan an add+delete of the same slot. Invariant: a claim
     * response never contains an org that the same request's `removable()`
     * list orders removed. A grant whose account has no membership row at
     * all (legacy backfilled data — a missing row cannot happen for grants
     * provisioned through the current flow, which always upserts Tracked)
     * is still served, since treating "no row" as blocking would silently
     * break those pre-existing grants.
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

        $untrackedAccountIds = $user->accounts()
            ->wherePivot('status', MembershipStatus::Untracked->value)
            ->pluck('accounts.id');

        $payloads = [];
        foreach ($device->grants()->live()->whereNull('deprovisioned_at')->get() as $grant) {
            if ($untrackedAccountIds->contains($grant->account_id)) {
                continue; // user is Untracked on this org — must not contradict removable()
            }

            $payload = $this->pendingPayloadFor($grant);
            if ($payload === null) {
                continue; // no live pending secret — revoked, already claimed-and-cleared, or never Claude
            }

            $payloads[] = $payload;
            if ($grant->status === GrantStatus::Pending) {
                $grant->forceFill(['status' => GrantStatus::Claimed, 'claimed_at' => Carbon::now()])->save();
            }
        }

        return $payloads;
    }

    /**
     * Reconstruct the client-facing grant payload from `$grant`'s own
     * durable secret field, or null when there is none to serve (revoked,
     * or already claimed-and-cleared). `device->grants()` mixes both
     * providers on purpose — the client's Claude-specific
     * `reconcile_provisioned` and its separate, standalone Codex pull both
     * read this SAME `accounts[]` response and filter by `provider`
     * themselves (see `codex_provisioned.py`'s own docstring) — so this
     * reconstructs whichever shape the grant actually holds, not Claude
     * only. `name`/`email`/`org_uuid`/`chatgpt_account_id` are read fresh
     * off `$grant->account` rather than duplicated onto the grant, since
     * they are always the account's own current values.
     *
     * @param  AccountProvisionedGrant  $grant  the grant to read
     * @return array<string, mixed>|null
     */
    private function pendingPayloadFor(AccountProvisionedGrant $grant): ?array
    {
        if ($grant->pending_claude_access_token !== null) {
            return [
                'name' => $grant->account->email,
                'email' => $grant->account->email,
                'org_uuid' => $grant->account->organization_uuid,
                'access_token' => $grant->pending_claude_access_token,
                'refresh_token' => $grant->pending_claude_refresh_token,
                'expires_at' => $grant->pending_claude_expires_at?->timestamp,
            ];
        }

        if ($grant->pending_codex_auth_json !== null) {
            return [
                'provider' => 'codex',
                'name' => $grant->account->name ?? $grant->account->email,
                'email' => $grant->account->email,
                'chatgpt_account_id' => $grant->account->codexCredential?->chatgpt_account_id,
                'auth_json' => $grant->pending_codex_auth_json,
            ];
        }

        return null;
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
            ->whereHas('claudeCredential', fn ($query) => $query->whereNotNull('organization_uuid'))
            ->whereKeyNot($confirmedAccountIds)
            ->with('claudeCredential')
            ->get()
            ->map(fn (Account $account): array => ['org_uuid' => $account->organization_uuid])
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
     * @param  array<int, array{org_uuid: string, refresh_token_expires_at: Carbon}>  $expiring  refresh-token deadlines the client observed
     * @return array{confirmed: int, deprovisioned: int}
     */
    public function confirmSetup(User $user, array $setUpOrgUuids, array $removedOrgUuids = [], ?Device $device = null, array $expiring = []): array
    {
        $this->recordObservedDeadlines($user, $expiring);

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
                // The secret exists to be fetched exactly once, by the machine
                // being set up. Once that machine reports success it is spent,
                // so it must not keep sitting on the grant row for anything
                // else to read.
                if ($device !== null) {
                    $grant = $device->grants()->where('account_id', $account->id)->latest('id')->first();
                    if ($grant !== null) {
                        $this->clearPendingSecret($grant);
                    }
                }
            } catch (Throwable $e) {
                report($e);

                continue;
            }
        }

        $deprovisioned = 0;
        if ($device !== null) {
            foreach (array_unique($removedOrgUuids) as $orgUuid) {
                $account = Account::query()->whereRelation('claudeCredential', 'organization_uuid', $orgUuid)->first();
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
                        $this->clearPendingSecret($grant);
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
        $account = Account::query()->whereRelation('claudeCredential', 'organization_uuid', $orgUuid)->first();
        if ($account === null) {
            return null;
        }

        $isGranted = $account->provisionedGrants()->live()
            ->whereHas('device', fn ($query) => $query->where('user_id', $user->id))
            ->exists();

        return $isGranted ? $account : null;
    }

    /**
     * Store the refresh-token deadlines a client reported for its own orgs.
     *
     * The client is the only party that sees this value between the server's
     * own refreshes, so it reports what it saw at setup time. Two guards make
     * that safe to trust:
     *
     * The same self-graft check the set_up loop uses — without it any
     * hook-token holder could overwrite the deadline on an account they do
     * not own by naming a fabricated org uuid.
     *
     * And a never-regress rule: a value is written only when nothing is
     * stored yet or the client's is LATER. The server's own refresher pushes
     * this deadline out by weeks on every successful rotation, and a confirm
     * request can arrive carrying an observation from before that happened;
     * taking the older value would invent an expiry scare that is already
     * resolved.
     *
     * @param  User  $user  the hook-authenticated user
     * @param  array<int, array{org_uuid: string, refresh_token_expires_at: Carbon}>  $expiring  what the client observed
     * @return void
     */
    private function recordObservedDeadlines(User $user, array $expiring): void
    {
        foreach ($expiring as $row) {
            $account = $this->accountWithLiveGrantFor($user, $row['org_uuid']);

            if ($account === null) {
                continue;
            }

            if ($account->oauth_refresh_expires_at === null
                || $row['refresh_token_expires_at']->isAfter($account->oauth_refresh_expires_at)) {
                $account->update(['oauth_refresh_expires_at' => $row['refresh_token_expires_at']]);
            }
        }
    }

    /**
     * Soft-revoke a grant: mark it Revoked and clear its secret field so a
     * future claim cannot re-serve it. (A grant already handed to a client
     * must be deleted separately at claude.ai using its token_uuid.)
     *
     * @param  AccountProvisionedGrant  $grant  the grant to revoke
     * @return void
     */
    public function revoke(AccountProvisionedGrant $grant): void
    {
        $grant->forceFill(['status' => GrantStatus::Revoked, 'revoked_at' => Carbon::now()])->save();
        $this->clearPendingSecret($grant);
    }

    /**
     * Null out every pending-secret column on `$grant`, regardless of which
     * provider actually populated one — the grant is either being claimed
     * (spent, one machine only) or revoked (dead), and either way nothing
     * should be able to read a secret off it again. Public so
     * {@see CodexProvisioningService::revoke()} (which already depends on
     * this service for {@see resolveProvisionTarget()}) can reuse it instead
     * of duplicating the field list.
     *
     * @param  AccountProvisionedGrant  $grant  the grant to clear
     * @return void
     */
    public function clearPendingSecret(AccountProvisionedGrant $grant): void
    {
        $grant->forceFill([
            'pending_claude_access_token' => null,
            'pending_claude_refresh_token' => null,
            'pending_claude_expires_at' => null,
            'pending_codex_auth_json' => null,
        ])->save();
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
            ->whereHas('claudeCredential', fn ($query) => $query->whereNotNull('organization_uuid'))
            ->with('claudeCredential')
            ->get()
            ->map(fn (Account $account): array => ['org_uuid' => $account->organization_uuid])
            ->all();
    }
}
