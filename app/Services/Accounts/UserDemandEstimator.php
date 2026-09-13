<?php

namespace App\Services\Accounts;

use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use App\Models\Event;
use App\Models\User;
use App\Services\Analytics\Concerns\ScopesEventsByFilters;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What each person actually needs, in tokens, and how expensive their tokens
 * are against a quota.
 *
 * Demand is measured per PERSON rather than per membership: moving someone
 * to another account moves their whole appetite with them, so the figure a
 * plan has to carry is their footprint across every account they touch.
 */
final class UserDemandEstimator
{
    use ScopesEventsByFilters;

    /**
     * Lower and upper bound on a quota weight. A weight comes from however
     * many solo windows happened to exist, which can be very few; clamping
     * keeps one freak measurement from doubling or erasing a person's
     * apparent demand in the plan.
     *
     * @var float
     */
    private const float MIN_WEIGHT = 0.5;

    /**
     * @var float
     */
    private const float MAX_WEIGHT = 2.0;

    /**
     * This person's demand, with the workings behind it so a recommendation
     * can show why it is sized the way it is. `per_day` takes the LARGER of
     * the trailing average and the peak consecutive-day run: once someone
     * has demonstrated a rate, the quiet days they spent throttling
     * themselves afterwards are evidence of a ceiling they hit, not of a
     * smaller appetite.
     *
     * @param  User  $user  the person to size
     * @param  RebalanceWindow  $window  how far back to read
     * @return array{weekly: float, per_day: float, trailing_avg_per_day: float, peak_avg_per_day: float, basis: string, days_of_history: int}
     */
    public function demandFor(User $user, RebalanceWindow $window): array
    {
        $daily = $this->dailyTotals($user, $window);

        $spanDays = max(1, $window->daysOr(max(1, count($daily))));
        $trailingAverage = (float) (array_sum($daily) / $spanDays);
        $peakAverage = $this->peakConsecutiveAverage(array_values($daily));

        $perDay = max($trailingAverage, $peakAverage);

        return [
            'weekly' => $perDay * 7,
            'per_day' => $perDay,
            'trailing_avg_per_day' => $trailingAverage,
            'peak_avg_per_day' => $peakAverage,
            'basis' => $peakAverage > $trailingAverage ? 'peak_rate' : 'trailing_average',
            'days_of_history' => $this->daysOfHistory($user, $window),
        ];
    }

    /**
     * How clustered this person's usage is inside a day: the busiest hour
     * over the mean hour. Two people with identical weekly totals are not
     * equally risky — the one who spends it all in a single afternoon is
     * the one who trips a 5-hour window.
     *
     * @param  User  $user  the person to measure
     * @param  RebalanceWindow  $window  how far back to read
     * @return float ratio of 1.0 or more; 1.0 when there is nothing to measure
     */
    public function burstFactor(User $user, RebalanceWindow $window): float
    {
        $hourExpr = $this->bucketExpression('hour', 'events.created_at');

        $hourly = Event::query()
            ->where('events.user_id', $user->id)
            ->when($window->since() !== null, fn ($query) => $query->where('events.created_at', '>=', $window->since()))
            ->selectRaw("{$hourExpr} as hour")
            ->selectRaw('SUM(events.tokens) as tokens')
            ->groupByRaw($hourExpr)
            ->pluck('tokens')
            ->map(fn ($tokens): int => (int) $tokens)
            ->all();

        if ($hourly === []) {
            return 1.0;
        }

        $average = array_sum($hourly) / count($hourly);

        return $average > 0.0 ? max($hourly) / $average : 1.0;
    }

    /**
     * Relative quota weight per user: how expensive their tokens are against
     * a quota compared with the typical member of the same account. 1.0 is
     * typical, 2.0 burns quota twice as fast per token.
     *
     * Measured only from 5-hour windows in which ONE person used the
     * account — those are the only windows where the utilisation moved for
     * a single, attributable reason. Each account's own median normalises
     * away how big that account is, so the weights compare people rather
     * than plans. A user with no solo window anywhere is simply absent from
     * the result, and the caller treats them as typical.
     *
     * @param  Collection<int, Account>  $accounts  the accounts to read windows from
     * @param  RebalanceWindow  $window  how far back to read
     * @return array<int, float> user id => weight, clamped
     */
    public function quotaWeights(Collection $accounts, RebalanceWindow $window): array
    {
        $relative = [];

        foreach ($accounts as $account) {
            $costs = $this->soloQuotaCosts($account, $window);
            if (count($costs) < 2) {
                continue; // with nobody to compare against, "heavier" has no meaning
            }

            $median = $this->median(array_values($costs));
            if ($median === null || $median <= 0.0) {
                continue;
            }

            foreach ($costs as $userId => $cost) {
                if ($cost > 0.0) {
                    $relative[$userId][] = $median / $cost;
                }
            }
        }

        $weights = [];
        foreach ($relative as $userId => $ratios) {
            $mean = array_sum($ratios) / count($ratios);
            $weights[$userId] = max(self::MIN_WEIGHT, min(self::MAX_WEIGHT, $mean));
        }

        return $weights;
    }

    /**
     * Tokens each solo user needed to move this account's util_5h by one
     * point, keyed by user id. A smaller number means heavier tokens.
     *
     * @param  Account  $account  the account whose windows to read
     * @param  RebalanceWindow  $window  how far back to read
     * @return array<int, float> user id => tokens per utilisation point
     */
    private function soloQuotaCosts(Account $account, RebalanceWindow $window): array
    {
        $snapshots = AccountUsageSnapshot::query()
            ->where('account_id', $account->id)
            ->whereNotNull('reset_5h_at')
            ->whereNotNull('util_5h')
            ->when($window->since() !== null, fn ($query) => $query->where('created_at', '>=', $window->since()))
            ->orderBy('created_at')
            ->get(['util_5h', 'reset_5h_at', 'created_at']);

        $events = Event::query()
            ->where('account_id', $account->id)
            ->when($window->since() !== null, fn ($query) => $query->where('created_at', '>=', $window->since()))
            ->orderBy('created_at')
            ->get(['user_id', 'tokens', 'created_at']);

        $costs = [];

        foreach ($this->groupByResetHour($snapshots) as $group) {
            if (count($group) < 2) {
                continue;
            }

            $first = $group[0];
            $last = $group[count($group) - 1];
            $utilDelta = (int) $last->util_5h - (int) $first->util_5h;
            if ($utilDelta <= 0) {
                continue;
            }

            $contributors = [];
            foreach ($events as $event) {
                $at = Carbon::parse($event->created_at);
                if ($at->betweenIncluded(Carbon::parse($first->created_at), Carbon::parse($last->created_at))) {
                    $contributors[(int) $event->user_id] = ($contributors[(int) $event->user_id] ?? 0) + (int) $event->tokens;
                }
            }

            if (count($contributors) !== 1) {
                continue; // shared window: the movement cannot be attributed
            }

            $userId = array_key_first($contributors);
            $tokens = $contributors[$userId];
            if ($tokens <= 0) {
                continue;
            }

            $costs[$userId][] = $tokens / $utilDelta;
        }

        return array_map(fn (array $samples): float => array_sum($samples) / count($samples), $costs);
    }

    /**
     * Group snapshots into quota windows, normalising the reset timestamp to
     * the hour — the prober records the same boundary a second either side
     * of it, and an unnormalised grouping would split one window in two.
     *
     * @param  Collection<int, AccountUsageSnapshot>  $snapshots  snapshots ordered by time
     * @return array<string, array<int, AccountUsageSnapshot>>
     */
    private function groupByResetHour(Collection $snapshots): array
    {
        $grouped = [];
        foreach ($snapshots as $snapshot) {
            $key = Carbon::parse($snapshot->reset_5h_at)->startOfHour()->toDateTimeString();
            $grouped[$key][] = $snapshot;
        }

        return $grouped;
    }

    /**
     * This person's tokens per calendar day, oldest first, days with no
     * usage omitted.
     *
     * @param  User  $user  the person to read
     * @param  RebalanceWindow  $window  how far back to read
     * @return array<string, int> day => tokens
     */
    private function dailyTotals(User $user, RebalanceWindow $window): array
    {
        return Event::query()
            ->where('user_id', $user->id)
            ->when($window->since() !== null, fn ($query) => $query->where('created_at', '>=', $window->since()))
            ->selectRaw('DATE(created_at) as day')
            ->selectRaw('SUM(tokens) as tokens')
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('tokens', 'day')
            ->map(fn ($tokens): int => (int) $tokens)
            ->all();
    }

    /**
     * The highest average across any run of `rebalance.peak_window_days`
     * consecutive recorded days. Someone with fewer recorded days than that
     * is averaged over the days they do have rather than scored zero —
     * one 14,000-token day is evidence of a 14,000-token/day appetite, not
     * of no appetite at all.
     *
     * @param  array<int, int>  $dailyTotals  tokens per day, oldest first
     * @return float
     */
    private function peakConsecutiveAverage(array $dailyTotals): float
    {
        if ($dailyTotals === []) {
            return 0.0;
        }

        $windowDays = max(1, (int) config('token_slayer.rebalance.peak_window_days'));
        $windowDays = min($windowDays, count($dailyTotals));

        $best = 0.0;
        for ($start = 0; $start <= count($dailyTotals) - $windowDays; $start++) {
            $slice = array_slice($dailyTotals, $start, $windowDays);
            $best = max($best, (float) (array_sum($slice) / $windowDays));
        }

        return $best;
    }

    /**
     * Days between this person's earliest recorded usage in the window and
     * now — how much evidence the demand figure rests on.
     *
     * @param  User  $user  the person to read
     * @param  RebalanceWindow  $window  how far back to read
     * @return int
     */
    private function daysOfHistory(User $user, RebalanceWindow $window): int
    {
        $earliest = Event::query()
            ->where('user_id', $user->id)
            ->when($window->since() !== null, fn ($query) => $query->where('created_at', '>=', $window->since()))
            ->min('created_at');

        return $earliest === null ? 0 : (int) now()->diffInDays(Carbon::parse($earliest), absolute: true);
    }

    /**
     * Median of the given values, or null when there are none.
     *
     * @param  array<int, float>  $values  the values to reduce
     * @return float|null
     */
    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
