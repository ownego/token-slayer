<?php

namespace App\Services;

use App\Enums\MembershipStatus;
use App\Exceptions\AccountConnectException;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\User;
use App\Support\CacheKeys;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Provisions a per-user OAuth grant. Durable, non-secret tracking (token_uuid
 * + timestamps) is written to the account_user pivot; the raw grant itself is
 * held ONLY in the cache, encrypted, with a 24 h TTL — never at rest in the DB
 * long-term, and never on the account's own probe grant.
 */
final class AccountProvisioningService
{
    /**
     * Cache-key prefix for a stored provisioned grant.
     *
     * @var string
     */
    public const string CACHE_KEY_PREFIX = 'provisioned:setup:';

    /**
     * How long an unclaimed provisioned grant lives in the cache (24 hours).
     *
     * @var int
     */
    public const int CACHE_TTL_SECONDS = 86400;

    /**
     * Build the service with the connect flow it delegates the code exchange to.
     *
     * @param  AccountConnectService  $connect  supplies the verifier-pull + code exchange
     * @return void
     */
    public function __construct(private readonly AccountConnectService $connect) {}

    /**
     * The cache key holding the encrypted raw grant for a (user, account) pair.
     *
     * @param  int  $userId  the provisioned user's id
     * @param  int  $accountId  the granted account's id
     * @return string the fully-qualified cache key
     */
    public function cacheKey(int $userId, int $accountId): string
    {
        return self::CACHE_KEY_PREFIX.$userId.':'.$accountId;
    }

    /**
     * Exchange a pasted PKCE code, write the tracking row to the (user, account)
     * pivot, and stash the encrypted raw grant in the cache (24 h TTL).
     *
     * @param  User  $user  the user being granted access
     * @param  Account  $account  the account to grant
     * @param  string  $state  the state from {@see AccountConnectService::start()}
     * @param  string  $pastedCode  the `code#state` the admin pasted
     * @return AccountUser the written pivot tracking row
     *
     * @throws AccountConnectException 'connect_state_expired' | 'connect_no_identity' | 'connect_identity_mismatch' when the pasted code's authorized identity doesn't match `$account`
     */
    public function provisionFromCode(User $user, Account $account, string $state, string $pastedCode): AccountUser
    {
        $token = $this->connect->exchangeVerifiedToken($state, $pastedCode, $account);

        $user->accounts()->syncWithoutDetaching([
            $account->id => [
                'status' => MembershipStatus::Tracked->value,
                'token_uuid' => $token['token_uuid'] ?? null,
                'provisioned_at' => Carbon::now(),
                'claimed_at' => null,
                'revoked_at' => null,
            ],
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
            $this->cacheKey($user->id, $account->id),
            Crypt::encryptString(json_encode($payload)),
            self::CACHE_TTL_SECONDS,
        );

        return AccountUser::query()
            ->where('user_id', $user->id)->where('account_id', $account->id)->firstOrFail();
    }

    /**
     * The user's grants that are provisioned and not revoked. Already-claimed
     * rows are INCLUDED — availability is decided by whether the encrypted
     * cache secret still exists (see {@see claim()}), so setup can be re-run
     * idempotently for the 24 h the secret lives.
     *
     * @param  User  $user  the user pulling grants
     * @return Collection<int, AccountUser>
     */
    public function claimableFor(User $user): Collection
    {
        return AccountUser::query()
            ->where('user_id', $user->id)
            ->whereNotNull('provisioned_at')
            ->whereNull('revoked_at')
            ->get();
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
     * Confirm the org accounts the CLI actually finished setting up, promoting
     * each membership to {@see MembershipStatus::Tracked}. Unknown org uuids
     * are skipped — this never creates an `Account` from client input.
     *
     * Security scope: an org is only promoted if the user already holds a
     * provisioned pivot for it (`account_user.provisioned_at` set and
     * `revoked_at` null) — see {@see Account::provisionedUsers()}. Without
     * that check a hook-token holder could self-graft membership onto any
     * org uuid it sends; an org the user was never provisioned for is
     * skipped exactly like an unknown org (not created, not counted).
     *
     * A failure writing one org's pivot is reported and swallowed so it
     * can't 500 the rest of the batch. Additive only: orgs absent from
     * `$orgUuids` are untouched, and nothing here is ever demoted, revoked,
     * or deleted. Incoming uuids are deduped so a repeated uuid can't
     * double-count `confirmed`.
     *
     * Deliberately does NOT call {@see CacheKeys::forgetAccountMembership()}
     * (owner decision): that 1 h aggregate cache only feeds the Events/Last-seen
     * columns, the status badge reads `pivot.status` live, and the tab's
     * Refresh action already clears it on demand.
     *
     * @param  User  $user  the hook-authenticated user confirming setup
     * @param  array<int, string>  $orgUuids  organization uuids the CLI confirmed it set up
     * @return int the number of memberships confirmed
     */
    public function confirmSetup(User $user, array $orgUuids): int
    {
        $confirmed = 0;

        foreach (array_unique($orgUuids) as $orgUuid) {
            $account = Account::query()->where('organization_uuid', $orgUuid)->first();
            if ($account === null) {
                continue; // unknown org — never create an account from client input
            }

            $isProvisionedForUser = $account->provisionedUsers()
                ->wherePivot('user_id', $user->id)
                ->wherePivotNull('revoked_at')
                ->exists();
            if (! $isProvisionedForUser) {
                continue; // never self-graft a membership the user wasn't provisioned for
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

                continue; // one bad org must not 500 the whole confirmation request
            }
        }

        return $confirmed;
    }

    /**
     * Soft-revoke a provision: mark it revoked and forget the cached grant so
     * a future claim cannot re-serve it. (A grant already handed to a client
     * must be deleted separately at claude.ai using its token_uuid.)
     *
     * @param  AccountUser  $pivot  the provision to revoke
     * @return void
     */
    public function revoke(AccountUser $pivot): void
    {
        $pivot->forceFill(['revoked_at' => Carbon::now()])->save();
        Cache::forget($this->cacheKey($pivot->user_id, $pivot->account_id));
    }
}
