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
     * @param  QuotaCostSolver  $solver  prices each person from the windows they shared, rather than only the ones they had alone
     */
    public function __construct(private readonly QuotaCostSolver $solver) {}

    /**
     * Lower and upper bound on a quota weight. However many windows a person
     * turns up in, the fit is still an estimate; clamping keeps one odd
     * solution from doubling or erasing somebody's apparent demand in the
     * plan.
     *
     * @var float
     */
    private const float MIN_WEIGHT = 0.5;

    /**
     * @var float
     */
    private const float MAX_WEIGHT = 2.0;

    /**
     * The quota window a demand figure has to be comparable with. Anthropic
     * meters a rolling week, so a week is the only span on which a person's
     * appetite and an account's capacity can be put side by side.
     *
     * @var int
     */
    private const int WINDOW_DAYS = 7;

    /**
     * Windows a person must have taken part in before their solved burn rate
     * is trusted. The solver can price somebody from windows they shared,
     * but not from one or two of them — that is how a single afternoon came
     * to discount somebody's whole week by a quarter.
     *
     * @var int
     */
    private const int MIN_PRICED_WINDOWS = 3;

    /**
     * This person's weekly demand, with the workings behind it so a
     * recommendation can show why it is sized the way it is.
     *
     * The figure is the LARGER of their heaviest recorded week and their
     * average week. Taking the heaviest is what corrects for suppressed
     * demand: once someone has shown what they want, the quiet days they
     * spent throttling themselves afterwards are evidence of a ceiling they
     * hit, not of a smaller appetite.
     *
     * It is measured over a whole week rather than extrapolated from a peak
     * day. An earlier version took the best two-day rate and multiplied by
     * seven, which assumes a person sustains their worst day for a full
     * week: on the real fleet that read every account at 1,500-3,700% of a
     * capacity measured the honest way, and no arrangement of people can fix
     * a demand figure that is forty times reality.
     *
     * @param  User  $user  the person to size
     * @param  RebalanceWindow  $window  how far back to read
     * @return array{weekly: float, per_day: float, trailing_avg_per_day: float, peak_week_tokens: float, basis: string, days_of_history: int}
     */
    public function demandFor(User $user, RebalanceWindow $window): array
    {
        $series = $this->zeroFilledDailyTotals($user, $window);

        $spanDays = max(1, $window->daysOr(max(1, count($series))));
        $trailingAveragePerDay = (float) (array_sum($series) / $spanDays);
        $averageWeek = $trailingAveragePerDay * self::WINDOW_DAYS;
        $peakWeek = $this->peakWeeklyTotal($series);

        $weekly = max($averageWeek, $peakWeek);

        return [
            'weekly' => $weekly,
            'per_day' => $weekly / self::WINDOW_DAYS,
            'trailing_avg_per_day' => $trailingAveragePerDay,
            'peak_week_tokens' => $peakWeek,
            'basis' => $peakWeek > $averageWeek ? 'peak_week' : 'trailing_average',
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
     * Each account's own median normalises away how big that account is, so
     * the weights compare people rather than plans. A user with no usable
     * measurement is simply absent from the result, and the caller treats
     * them as typical.
     *
     * Windows are used whether or not the person was alone in them:
     * {@see QuotaCostSolver} treats each one as an equation and solves for
     * everybody at once, which is what makes fifteen shared windows worth
     * more than the one or two solo ones an account happens to have. A
     * person still needs {@see MIN_PRICED_WINDOWS} windows of their own
     * before the answer is applied, and anyone the windows cannot separate
     * from their companions is left out entirely — being treated as typical
     * is the right answer when there is no evidence to the contrary.
     *
     * @param  Collection<int, Account>  $accounts  the accounts to read windows from
     * @param  RebalanceWindow  $window  how far back to read
     * @return array<int, array{weight: float, windows: int, clamped: bool}> user id => the weight, the windows behind it, and whether the clamp is what set it rather than the measurement
     */
    public function quotaWeights(Collection $accounts, RebalanceWindow $window): array
    {
        $relative = [];
        $windowCounts = [];

        foreach ($accounts as $account) {
            $windows = $this->quotaWindows($account, $window);
            $appearances = $this->appearances($windows);
            $costs = $this->solver->solve($windows);

            $measured = [];
            foreach ($costs as $userId => $cost) {
                if ($cost > 0.0 && ($appearances[$userId] ?? 0) >= self::MIN_PRICED_WINDOWS) {
                    $measured[$userId] = ['cost' => $cost, 'windows' => $appearances[$userId]];
                }
            }

            if (count($measured) < 2) {
                continue; // with nobody to compare against, "heavier" has no meaning
            }

            $median = $this->median(array_column($measured, 'cost'));
            if ($median === null || $median <= 0.0) {
                continue;
            }

            foreach ($measured as $userId => $entry) {
                $relative[$userId][] = $median / $entry['cost'];
                $windowCounts[$userId] = ($windowCounts[$userId] ?? 0) + $entry['windows'];
            }
        }

        $weights = [];
        foreach ($relative as $userId => $ratios) {
            $mean = array_sum($ratios) / count($ratios);
            $clamped = max(self::MIN_WEIGHT, min(self::MAX_WEIGHT, $mean));
            $weights[$userId] = [
                'weight' => $clamped,
                'windows' => $windowCounts[$userId],
                // A weight sitting on the rail is the most this is willing to
                // credit, not what it read. Printed bare, a clamped 2.00 says
                // "measured at twice typical" when the fit said five times and
                // was overruled -- and on prod that rail was carrying half of
                // one member's planned demand.
                'clamped' => abs($clamped - $mean) > 1e-9,
            ];
        }

        return $weights;
    }

    /**
     * This account's 5-hour quota windows as equations: how far the dial
     * moved, and who spent what while it did.
     *
     * @param  Account  $account  the account whose windows to read
     * @param  RebalanceWindow  $window  how far back to read
     * @return array<int, array{delta: float, tokens: array<int, float>}>
     */
    private function quotaWindows(Account $account, RebalanceWindow $window): array
    {
        $snapshots = AccountUsageSnapshot::query()
            ->where('account_id', $account->id)
            ->whereNotNull('reset_5h_at')
            ->whereNotNull('util_5h')
            ->when($window->since() !== null, fn ($query) => $query->where('created_at', '>=', $window->since()))
            ->orderBy('created_at')
            ->get(['util_5h', 'reset_5h_at', 'created_at']);

        // Events are flattened into parallel arrays of plain integers, once,
        // rather than re-parsed per window. Scanning every event inside every
        // window and building a Carbon for each comparison is quadratic and
        // measured 24 seconds on a fleet of seven accounts and 37,000 events
        // — effectively the entire cost of a recalculation.
        $events = Event::query()
            ->where('account_id', $account->id)
            ->when($window->since() !== null, fn ($query) => $query->where('created_at', '>=', $window->since()))
            ->orderBy('created_at')
            ->get(['user_id', 'tokens', 'created_at']);

        $times = [];
        $userIds = [];
        $tokenCounts = [];
        foreach ($events as $event) {
            $times[] = Carbon::parse($event->created_at)->getTimestamp();
            $userIds[] = (int) $event->user_id;
            $tokenCounts[] = (int) $event->tokens;
        }

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

            $from = Carbon::parse($first->created_at)->getTimestamp();
            $to = Carbon::parse($last->created_at)->getTimestamp();

            $contributors = [];
            for ($index = $this->firstAtOrAfter($times, $from); $index < count($times) && $times[$index] <= $to; $index++) {
                $contributors[$userIds[$index]] = ($contributors[$userIds[$index]] ?? 0) + $tokenCounts[$index];
            }

            if ($contributors === []) {
                continue; // the dial moved with nobody on record; nothing to attribute
            }

            $costs[] = [
                'delta' => (float) $utilDelta,
                'tokens' => array_map(fn (int $tokens): float => (float) $tokens, $contributors),
            ];
        }

        return $costs;
    }

    /**
     * How many windows each person took part in — the evidence behind
     * whatever the solver says about them.
     *
     * @param  array<int, array{delta: float, tokens: array<int, float>}>  $windows  the account's windows
     * @return array<int, int> user id => windows they spent anything in
     */
    private function appearances(array $windows): array
    {
        $counts = [];

        foreach ($windows as $window) {
            foreach ($window['tokens'] as $userId => $tokens) {
                if ($tokens > 0.0) {
                    $counts[$userId] = ($counts[$userId] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }

    /**
     * Index of the first timestamp at or after `$target` in an ascending
     * list, or the list length when every entry is earlier.
     *
     * @param  array<int, int>  $times  unix timestamps, ascending
     * @param  int  $target  the moment to seek
     * @return int
     */
    private function firstAtOrAfter(array $times, int $target): int
    {
        $low = 0;
        $high = count($times);

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($times[$middle] < $target) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
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
     * This person's tokens per calendar day, oldest first, with every idle
     * day between their first and last active one present as a zero. The
     * gaps matter: two heavy days three weeks apart are not one heavy week,
     * and a series that quietly closes the gap up would read them as one.
     *
     * @param  User  $user  the person to read
     * @param  RebalanceWindow  $window  how far back to read
     * @return array<int, int> tokens per day, oldest first
     */
    private function zeroFilledDailyTotals(User $user, RebalanceWindow $window): array
    {
        $byDay = $this->dailyTotals($user, $window);
        if ($byDay === []) {
            return [];
        }

        $days = array_keys($byDay);
        $cursor = Carbon::parse((string) $days[0])->startOfDay();
        $last = Carbon::parse((string) $days[count($days) - 1])->startOfDay();

        $series = [];
        while ($cursor->lessThanOrEqualTo($last)) {
            $series[] = $byDay[$cursor->toDateString()] ?? 0;
            $cursor->addDay();
        }

        return $series;
    }

    /**
     * The most tokens this person got through in any seven consecutive days
     * on record. Someone with less than a week of history is credited with
     * everything they have, rather than scored against days that had not
     * happened yet.
     *
     * @param  array<int, int>  $series  tokens per day, oldest first, gaps zero-filled
     * @return float
     */
    private function peakWeeklyTotal(array $series): float
    {
        if ($series === []) {
            return 0.0;
        }

        $windowDays = min(self::WINDOW_DAYS, count($series));
        $running = 0;
        $best = 0.0;

        foreach ($series as $index => $tokens) {
            $running += $tokens;
            if ($index >= $windowDays) {
                $running -= $series[$index - $windowDays];
            }
            if ($index >= $windowDays - 1) {
                $best = max($best, (float) $running);
            }
        }

        return $best;
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
