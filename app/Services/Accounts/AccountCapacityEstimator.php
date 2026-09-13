<?php

namespace App\Services\Accounts;

use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use App\Models\Event;
use App\Services\QuotaProjection;

/**
 * Per-account capacity numbers for the rebalance recommender, derived from
 * the account's own trailing usage history rather than a hardcoded per-plan
 * token table — Anthropic doesn't publish one, and a higher-tier account
 * naturally shows a larger {@see tokensPerPercent()} without this class ever
 * needing to know which plan it is.
 */
final class AccountCapacityEstimator
{
    /**
     * Empirical tokens-per-1%-of-util_7d ratio for this account: its own
     * trailing 7-day token sum divided by its own current util_7d reading.
     * Returns 0 when the account has no usage snapshot yet (nothing to
     * divide by) — callers must treat 0 as "unknown," not "free."
     *
     * @param  Account  $account  the account to estimate capacity for
     * @return float tokens consumed per 1 percentage point of util_7d
     */
    public function tokensPerPercent(Account $account): float
    {
        $util7d = $this->latestSnapshot($account)?->util_7d ?? 0;
        if ($util7d <= 0) {
            return 0.0;
        }

        $tokens = (int) Event::query()
            ->where('account_id', $account->id)
            ->where('created_at', '>=', now()->subDays(7))
            ->sum('tokens');

        return $tokens / $util7d;
    }

    /**
     * The account's util_7d projected forward to its own reset time, using
     * the peak single-day token rate observed in the trailing rebalance
     * trend window rather than the average rate since the window opened —
     * the average is blind to a burn rate that spiked early and was then
     * throttled down (self- or ceiling-imposed), which is exactly the "runs
     * dry with days still left" pattern this estimator exists to catch.
     * Unclamped: a value past 100 is the point, not a bug. Returns the raw
     * util_7d reading unprojected when the latest snapshot has no
     * `reset_7d_at` — real probed data can carry this (e.g. a snapshot with
     * no rate-limit window reported yet), and there is no reset time to
     * project toward in that case.
     *
     * @param  Account  $account  the account to project
     * @return int the projected util_7d at this account's own reset time (may exceed 100 or be negative)
     */
    public function projectedUtilAtReset(Account $account): int
    {
        $snapshot = $this->latestSnapshot($account);
        if ($snapshot === null) {
            return 0;
        }
        if ($snapshot->reset_7d_at === null) {
            return $snapshot->util_7d ?? 0;
        }

        return QuotaProjection::projectedAtResetFromDailyRate(
            $snapshot->util_7d,
            $this->peakDailyRatePercent($account),
            $snapshot->reset_7d_at,
            now(),
        );
    }

    /**
     * Tokens' worth of usage that must leave this account before its reset
     * to avoid exhausting before then — 0 when the projection stays at or
     * under 100.
     *
     * @param  Account  $account  the account to evaluate
     * @return float overflow tokens, 0 or more
     */
    public function overflowTokens(Account $account): float
    {
        $over = max(0, $this->projectedUtilAtReset($account) - 100);

        return $over * $this->tokensPerPercent($account);
    }

    /**
     * Genuine spare token capacity through to reset, safe to hand to
     * another user — 0 when the projection is already at or over 100.
     *
     * @param  Account  $account  the account to evaluate
     * @return float headroom tokens, 0 or more
     */
    public function headroomTokens(Account $account): float
    {
        $under = max(0, 100 - $this->projectedUtilAtReset($account));

        return $under * $this->tokensPerPercent($account);
    }

    /**
     * The largest single day's token total for this account within the
     * configured trend window, expressed as a percentage-points-per-day
     * rate via {@see tokensPerPercent()}. 0 when there is no history or no
     * measurable capacity yet.
     *
     * @param  Account  $account  the account to measure
     * @return float peak daily burn rate, in util_7d percentage points per day
     */
    private function peakDailyRatePercent(Account $account): float
    {
        $tokensPerPercent = $this->tokensPerPercent($account);
        if ($tokensPerPercent <= 0.0) {
            return 0.0;
        }

        $trendDays = (int) config('token_slayer.rebalance.trend_window_days');

        $peakDailyTokens = (int) Event::query()
            ->where('account_id', $account->id)
            ->where('created_at', '>=', now()->subDays($trendDays))
            ->selectRaw('DATE(created_at) as day')
            ->selectRaw('SUM(tokens) as tokens')
            ->groupBy('day')
            ->get()
            ->max('tokens');

        return $peakDailyTokens / $tokensPerPercent;
    }

    /**
     * The account's most recent quota-utilization reading.
     *
     * @param  Account  $account  the account to look up
     * @return AccountUsageSnapshot|null the latest snapshot, or null when none exist
     */
    private function latestSnapshot(Account $account): ?AccountUsageSnapshot
    {
        return AccountUsageSnapshot::query()
            ->where('account_id', $account->id)
            ->latest('created_at')
            ->first();
    }
}
