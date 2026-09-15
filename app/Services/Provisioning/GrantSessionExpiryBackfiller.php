<?php

namespace App\Services\Provisioning;

use App\Models\AccountProvisionedGrant;
use App\Services\Attribution\ExpiringAccountsQuery;

/**
 * Guesses a session deadline for a live grant that has none on record — one
 * whose secret was cleared before `session_expires_at` existed, so the real
 * value Anthropic once returned is gone for good. Anchored on `provisioned_at`
 * (the moment the token was actually minted) plus the refresh-token lifetime
 * measured live from real exchange responses (`tests/fixtures/anthropic/
 * token.json`/`refresh.json`, ~2,546,224s / 29.47 days). It is a guess, not a
 * reading — every row it touches is marked `session_expires_at_estimated`, and
 * {@see ExpiringAccountsQuery} discards one whose
 * account has since recorded real activity past it.
 */
final class GrantSessionExpiryBackfiller
{
    /**
     * The typical refresh-token lifetime, in seconds, measured from live
     * Anthropic exchange responses.
     *
     * @var int
     */
    public const int TYPICAL_SESSION_LIFETIME_SECONDS = 2546224;

    /**
     * Stamp an estimated `session_expires_at` onto every live grant that has
     * none recorded.
     *
     * @return int how many grants were backfilled
     */
    public function backfill(): int
    {
        $grants = AccountProvisionedGrant::query()
            ->live()
            ->whereNull('session_expires_at')
            ->get();

        foreach ($grants as $grant) {
            $grant->forceFill([
                'session_expires_at' => $grant->provisioned_at->copy()->addSeconds(self::TYPICAL_SESSION_LIFETIME_SECONDS),
                'session_expires_at_estimated' => true,
            ])->save();
        }

        return $grants->count();
    }
}
