<?php

namespace App\Services\Accounts;

use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use App\Models\Event;
use App\Models\User;
use App\Services\Analytics\Concerns\ScopesEventsByFilters;

/**
 * Per-user demand numbers for the rebalance recommender, scoped to one
 * account's usage history. Corrects for two real patterns a plain average
 * misses: a user who throttled their own usage after a burst (their true
 * demand is the burst, not the average that includes the quiet days after
 * it), and per-token quota cost that varies by user (see
 * {@see cleanWindowQuotaWeight()}).
 */
final class UserDemandEstimator
{
    use ScopesEventsByFilters;

    /**
     * The user's estimated daily token demand on this account: the larger
     * of their trailing-7d average and their peak consecutive-day average
     * within the trend window. Using the larger of the two, rather than a
     * blended average, is the fix for self-throttling — once a user has
     * demonstrated a burn rate, averaging in days they deliberately pulled
     * back afterward would understate what they actually need.
     *
     * @param  User  $user  the user to estimate demand for
     * @param  Account  $account  the account their usage is scoped to
     * @return float estimated tokens per day
     */
    public function baselineTokensPerDay(User $user, Account $account): float
    {
        $trailingAverage = $this->averageTokensPerDay($user, $account, 7);
        $peakAverage = $this->peakConsecutiveDaysAverage($user, $account);

        return max($trailingAverage, $peakAverage);
    }

    /**
     * How clustered this user's usage is within a day: peak-hour tokens
     * divided by average-hour tokens over the trend window. A user whose
     * usage clusters into short bursts poses more util_5h risk than one who
     * spreads the same weekly total evenly, even though their daily/weekly
     * totals could look identical. Returns 1.0 (no burst signal) when the
     * user has no events in the window.
     *
     * @param  User  $user  the user to measure
     * @param  Account  $account  the account their usage is scoped to
     * @return float peak-hour-to-average-hour ratio, 1.0 or more
     */
    public function burstFactor(User $user, Account $account): float
    {
        $trendDays = (int) config('token_slayer.rebalance.trend_window_days');
        $hourExpr = $this->bucketExpression('hour', 'events.created_at');

        $hourlyTotals = Event::query()
            ->where('events.account_id', $account->id)
            ->where('events.user_id', $user->id)
            ->where('events.created_at', '>=', now()->subDays($trendDays))
            ->selectRaw("{$hourExpr} as hour")
            ->selectRaw('SUM(events.tokens) as tokens')
            ->groupByRaw($hourExpr)
            ->pluck('tokens')
            ->map(fn ($tokens): int => (int) $tokens)
            ->all();

        if ($hourlyTotals === []) {
            return 1.0;
        }

        $average = array_sum($hourlyTotals) / count($hourlyTotals);
        if ($average <= 0.0) {
            return 1.0;
        }

        return max($hourlyTotals) / $average;
    }

    /**
     * Plain average tokens/day over the trailing `$days` for this user on
     * this account.
     *
     * @param  User  $user  the user to measure
     * @param  Account  $account  the account their usage is scoped to
     * @param  int  $days  the trailing window length, in days
     * @return float average tokens per day
     */
    private function averageTokensPerDay(User $user, Account $account, int $days): float
    {
        $total = (int) Event::query()
            ->where('account_id', $account->id)
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subDays($days))
            ->sum('tokens');

        return $total / $days;
    }

    /**
     * The highest average tokens/day across any run of
     * `rebalance.peak_window_days` consecutive days within the trend
     * window, for this user on this account. 0 when there is no usage in
     * the window at all.
     *
     * @param  User  $user  the user to measure
     * @param  Account  $account  the account their usage is scoped to
     * @return float peak consecutive-day average tokens per day
     */
    private function peakConsecutiveDaysAverage(User $user, Account $account): float
    {
        $lookbackDays = (int) config('token_slayer.rebalance.trend_window_days');
        $windowDays = (int) config('token_slayer.rebalance.peak_window_days');

        $dailyTotals = Event::query()
            ->where('account_id', $account->id)
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subDays($lookbackDays))
            ->selectRaw('DATE(created_at) as day')
            ->selectRaw('SUM(tokens) as tokens')
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('tokens')
            ->map(fn ($tokens): int => (int) $tokens)
            ->values()
            ->all();

        $best = 0.0;
        for ($start = 0; $start <= count($dailyTotals) - $windowDays; $start++) {
            $slice = array_slice($dailyTotals, $start, $windowDays);
            $best = max($best, array_sum($slice) / $windowDays);
        }

        return $best;
    }

    /**
     * This user's own observed quota-cost-per-token on this account,
     * measured only from 5h windows (`account_usage_snapshots.reset_5h_at`
     * groups) where they were the account's ONLY contributor —
     * unconfounded, no regression, no guess at how to split credit between
     * simultaneous users. Expressed in the same units as
     * {@see AccountCapacityEstimator::tokensPerPercent()} (tokens per 1% of
     * util_5h) so a caller can use whichever is available interchangeably.
     * Returns null when the user never had a qualifying clean window in the
     * trend window — the caller falls back to the account's own uniform
     * `tokensPerPercent()`.
     *
     * @param  User  $user  the user to measure
     * @param  Account  $account  the account to measure their usage against
     * @return float|null tokens per 1% of util_5h, or null with no qualifying window
     */
    public function cleanWindowQuotaWeight(User $user, Account $account): ?float
    {
        $trendDays = (int) config('token_slayer.rebalance.trend_window_days');

        $resetTimes = AccountUsageSnapshot::query()
            ->where('account_id', $account->id)
            ->where('created_at', '>=', now()->subDays($trendDays))
            ->distinct()
            ->pluck('reset_5h_at');

        $percentPerTokenRatios = [];

        foreach ($resetTimes as $resetAt) {
            $windowSnapshots = AccountUsageSnapshot::query()
                ->where('account_id', $account->id)
                ->where('reset_5h_at', $resetAt)
                ->orderBy('created_at')
                ->get(['util_5h', 'created_at']);

            if ($windowSnapshots->count() < 2) {
                continue;
            }

            $utilDelta = $windowSnapshots->last()->util_5h - $windowSnapshots->first()->util_5h;
            if ($utilDelta <= 0) {
                continue;
            }

            $windowStart = $windowSnapshots->first()->created_at;
            $windowEnd = $windowSnapshots->last()->created_at;

            $contributorIds = Event::query()
                ->where('account_id', $account->id)
                ->whereBetween('created_at', [$windowStart, $windowEnd])
                ->distinct()
                ->pluck('user_id');

            if ($contributorIds->count() !== 1 || ! $contributorIds->contains($user->id)) {
                continue;
            }

            $userTokens = (int) Event::query()
                ->where('account_id', $account->id)
                ->where('user_id', $user->id)
                ->whereBetween('created_at', [$windowStart, $windowEnd])
                ->sum('tokens');

            if ($userTokens <= 0) {
                continue;
            }

            $percentPerTokenRatios[] = $utilDelta / $userTokens;
        }

        if ($percentPerTokenRatios === []) {
            return null;
        }

        $averagePercentPerToken = array_sum($percentPerTokenRatios) / count($percentPerTokenRatios);

        return $averagePercentPerToken > 0.0 ? 1 / $averagePercentPerToken : null;
    }
}
