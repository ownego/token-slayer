<?php

namespace App\Support;

use App\Services\Provisioning\LegacyGrantBackfiller;
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
     * Codex chatgpt-account-id → account-id resolver map key.
     *
     * @var string
     */
    public const string ACCOUNTS_CODEX_ORG_MAP = 'accounts:codex-org-map';

    /**
     * Lowercase-email → account-id resolver map key, Codex-provider
     * accounts only — kept separate from ACCOUNTS_EMAIL_MAP so a Codex
     * event's email claim can never resolve against a Claude account (or
     * vice versa) purely by email coincidence.
     *
     * @var string
     */
    public const string ACCOUNTS_CODEX_EMAIL_MAP = 'accounts:codex-email-map';

    /**
     * How long a provisioned grant's encrypted secret lives in the cache
     * (24 hours). After expiry the raw grant is gone forever; Reissue is
     * the only recovery path.
     *
     * @var int
     */
    public const int PROVISIONED_GRANT_TTL_SECONDS = 86400;

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
     * Build the cache key holding one grant's encrypted raw secret.
     *
     * @param  int  $grantId  the account_provisioned_grants row id
     * @return string
     */
    public static function provisionedGrant(int $grantId): string
    {
        return "provisioned:grant:{$grantId}";
    }

    /**
     * Build the legacy pre-device cache key for a stored provisioned grant,
     * keyed by (user, account) rather than by grant id. Only read by
     * {@see LegacyGrantBackfiller} while copying an
     * in-flight (<24 h) secret onto its new per-grant key during the
     * devices/grants migration; never written after that migration runs.
     *
     * @param  int  $userId  the provisioned user's id
     * @param  int  $accountId  the granted account's id
     * @return string
     */
    public static function legacyProvisionedSetup(int $userId, int $accountId): string
    {
        return "provisioned:setup:{$userId}:{$accountId}";
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
        Cache::forget(self::ACCOUNTS_CODEX_ORG_MAP);
        Cache::forget(self::ACCOUNTS_CODEX_EMAIL_MAP);
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

    /**
     * Forget one grant's encrypted raw secret.
     *
     * @param  int  $grantId  the account_provisioned_grants row id
     * @return void
     */
    public static function forgetProvisionedGrant(int $grantId): void
    {
        Cache::forget(self::provisionedGrant($grantId));
    }
}
