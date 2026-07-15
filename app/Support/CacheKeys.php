<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Central registry of every application cache key and the helpers that
 * invalidate them. Services reference these constants/methods instead of
 * owning key strings, so invalidation logic lives in one place.
 */
final class CacheKeys
{
    /**
     * Global damage-totals aggregate key.
     *
     * @var string
     */
    public const string DAMAGE_TOTALS = 'damage-totals:global';

    /**
     * Lowercase-email → account-id resolver map key.
     *
     * @var string
     */
    public const string ACCOUNTS_EMAIL_MAP = 'accounts:email-map';

    /**
     * Organization-uuid → account-id resolver map key.
     *
     * @var string
     */
    public const string ACCOUNTS_ORG_MAP = 'accounts:org-map';

    /**
     * Build the cache key for one account's tracked-members aggregate map.
     *
     * @param  int  $accountId  the owning account id
     * @return string
     */
    public static function trackedMembers(int $accountId): string
    {
        return "account:{$accountId}:tracked-members";
    }

    /**
     * Build the cache key for one account's untracked-contributors aggregate map.
     *
     * @param  int  $accountId  the owning account id
     * @return string
     */
    public static function untrackedContributors(int $accountId): string
    {
        return "account:{$accountId}:untracked-contributors";
    }

    /**
     * Build the cache key for one account's set of known member user ids —
     * the ingest recorder's existence guard.
     *
     * @param  int  $accountId  the owning account id
     * @return string
     */
    public static function membershipPairs(int $accountId): string
    {
        return "account:{$accountId}:membership-pairs";
    }

    /**
     * Forget the global damage-totals aggregate.
     *
     * @return void
     */
    public static function forgetDamageTotals(): void
    {
        Cache::forget(self::DAMAGE_TOTALS);
    }

    /**
     * Forget both resolver maps (email and organization uuid).
     *
     * @return void
     */
    public static function forgetAccountMaps(): void
    {
        Cache::forget(self::ACCOUNTS_EMAIL_MAP);
        Cache::forget(self::ACCOUNTS_ORG_MAP);
    }

    /**
     * Forget both per-account membership aggregate maps at once.
     *
     * @param  int  $accountId  the account whose membership caches to drop
     * @return void
     */
    public static function forgetAccountMembership(int $accountId): void
    {
        Cache::forget(self::trackedMembers($accountId));
        Cache::forget(self::untrackedContributors($accountId));
    }

    /**
     * Forget one account's ingest existence-guard set.
     *
     * @param  int  $accountId  the account whose pair cache to drop
     * @return void
     */
    public static function forgetMembershipPairs(int $accountId): void
    {
        Cache::forget(self::membershipPairs($accountId));
    }
}
