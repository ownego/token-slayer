<?php

namespace App\Services\Accounts;

use App\Enums\MembershipStatus;
use App\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Ensures an `account_user` row exists for a (user, account) pair the moment a
 * user contributes an event, defaulting new rows to untracked. Guarded by a
 * per-account cached set of known member ids so the hot ingest path skips the
 * DB when the pair is already known; the unique constraint + `insertOrIgnore`
 * is the real idempotency guard, so a cold cache only costs a no-op insert and
 * never a duplicate or a status downgrade.
 */
final class AccountMembershipRecorder
{
    /**
     * How long the per-account known-pairs set is cached.
     *
     * @var int
     */
    private const int TTL_SECONDS = 3600;

    /**
     * Record that a user contributed to an account, materializing an untracked
     * membership row if none exists yet.
     *
     * @param  int  $userId  the contributing user's id
     * @param  int  $accountId  the account the event was attributed to
     * @return void
     */
    public function record(int $userId, int $accountId): void
    {
        $key = CacheKeys::membershipPairs($accountId);
        $known = Cache::get($key, []);

        if (in_array($userId, $known, true)) {
            return;
        }

        DB::table('account_user')->insertOrIgnore([
            'account_id' => $accountId,
            'user_id' => $userId,
            'status' => MembershipStatus::Untracked->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $known[] = $userId;
        Cache::put($key, $known, self::TTL_SECONDS);
    }
}
