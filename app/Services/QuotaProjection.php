<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * Stateless forecaster for a rate-limit utilization gauge. Given a 0–100
 * reading and the current window's start/reset boundaries, it linearly
 * extrapolates the observed burn rate to the moment the window resets —
 * turning a static "27% right now" gauge into an early "you will hit 88%
 * by the time it resets" warning. Framework-free so it is unit-testable in
 * isolation.
 */
final class QuotaProjection
{
    /**
     * Project the utilization percent expected at the window's reset time,
     * assuming the burn rate observed so far this window holds. Returns the
     * current value unchanged when no time has elapsed since the window
     * started (divide-by-zero guard); always clamped to 0–100.
     *
     * @param  int  $current  the latest utilization reading, 0–100
     * @param  Carbon  $windowStart  when the current window began (reset minus window length)
     * @param  Carbon  $resetAt  when the current window resets to zero
     * @param  Carbon  $now  the moment the projection is made
     * @return int the clamped projected utilization percent at reset
     */
    public static function projectedAtReset(int $current, Carbon $windowStart, Carbon $resetAt, Carbon $now): int
    {
        $elapsed = $windowStart->diffInSeconds($now, absolute: false);
        $remaining = $now->diffInSeconds($resetAt, absolute: false);

        if ($elapsed <= 0 || $remaining <= 0) {
            return max(0, min(100, $current));
        }

        $ratePerSecond = $current / $elapsed;
        $projected = (int) round($current + $ratePerSecond * $remaining);

        return max(0, min(100, $projected));
    }

    /**
     * Project the utilization percent expected at the window's reset time
     * using an explicitly supplied daily rate rather than one inferred from
     * elapsed time since the window opened — for callers computing the rate
     * a different way (e.g. the peak observed daily burn, not the plain
     * average since the window started). Unlike {@see projectedAtReset()},
     * the result is NOT clamped to 0–100: a caller detecting "this account
     * will exhaust before its reset" needs to see how far past 100 the
     * projection lands, and clamping would hide exactly that.
     *
     * @param  int  $current  the latest utilization reading, 0–100
     * @param  float  $ratePerDay  utilization percentage points consumed per day, at the rate to project forward
     * @param  Carbon  $resetAt  when the current window resets to zero
     * @param  Carbon  $now  the moment the projection is made
     * @return int the projected utilization percent at reset, unclamped
     */
    public static function projectedAtResetFromDailyRate(int $current, float $ratePerDay, Carbon $resetAt, Carbon $now): int
    {
        $remainingDays = $now->diffInSeconds($resetAt, absolute: false) / 86400;

        if ($remainingDays <= 0) {
            return $current;
        }

        return (int) round($current + $ratePerDay * $remainingDays);
    }
}
