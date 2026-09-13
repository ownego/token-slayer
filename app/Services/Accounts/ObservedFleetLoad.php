<?php

namespace App\Services\Accounts;

use App\Models\Account;
use App\Models\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What the fleet actually did, measured straight off the event ledger with
 * no model in between.
 *
 * This exists because a projection the team can disprove from memory is
 * worthless. The demand model sums each person's heaviest week as though
 * those peaks all landed together, which reads the fleet 35% heavier than
 * anything that has ever happened; it also pins each person to one account,
 * while in practice the heavy users already spill onto a second when the
 * first runs dry. Both are defensible as planning assumptions and neither is
 * an observation, so the observations are reported alongside them.
 */
final class ObservedFleetLoad
{
    /**
     * The quota window everything here is measured over, matching the one
     * Anthropic meters.
     *
     * @var int
     */
    private const int WINDOW_DAYS = 7;

    /**
     * Measure what each account, and the fleet as a whole, actually got
     * through in its heaviest seven consecutive days.
     *
     * @param  Collection<int, Account>  $accounts  the accounts in scope
     * @param  array<int, float>  $capacities  account id => weekly capacity in tokens
     * @param  RebalanceWindow  $window  how far back to read
     * @return array{per_account: array<int, array{peak_tokens: float, peak_percent: float}>, accounts_over_capacity: int, accounts_measured: int, fleet_peak_tokens: float, fleet_peak_percent: float, fleet_median_tokens: float, fleet_median_percent: float}
     */
    public function measure(Collection $accounts, array $capacities, RebalanceWindow $window): array
    {
        $dailyByAccount = $this->dailyTotalsByAccount($accounts, $window);

        $perAccount = [];
        $over = 0;

        foreach ($accounts as $account) {
            $capacity = $capacities[$account->id] ?? null;
            if ($capacity === null || $capacity <= 0.0) {
                continue; // nothing to measure it against
            }

            $peak = $this->peakWindow($dailyByAccount[$account->id] ?? []);
            $percent = $peak * 100 / $capacity;

            $perAccount[$account->id] = ['peak_tokens' => $peak, 'peak_percent' => $percent];
            if ($percent > 100.0) {
                $over++;
            }
        }

        $capacityTotal = 0.0;
        foreach (array_keys($perAccount) as $accountId) {
            $capacityTotal += $capacities[$accountId];
        }

        // The fleet's own series, not the per-account peaks added up: those
        // peaks fall in different weeks, and summing them invents a week the
        // fleet never had.
        $fleetDaily = [];
        foreach ($dailyByAccount as $accountId => $daily) {
            if (! array_key_exists($accountId, $perAccount)) {
                continue;
            }
            foreach ($daily as $day => $tokens) {
                $fleetDaily[$day] = ($fleetDaily[$day] ?? 0) + $tokens;
            }
        }
        ksort($fleetDaily);

        $windows = $this->allWindows($fleetDaily);

        return [
            'per_account' => $perAccount,
            'accounts_over_capacity' => $over,
            'accounts_measured' => count($perAccount),
            'fleet_peak_tokens' => $windows === [] ? 0.0 : max($windows),
            'fleet_peak_percent' => $capacityTotal > 0.0 && $windows !== [] ? max($windows) * 100 / $capacityTotal : 0.0,
            'fleet_median_tokens' => $this->median($windows),
            'fleet_median_percent' => $capacityTotal > 0.0 ? $this->median($windows) * 100 / $capacityTotal : 0.0,
        ];
    }

    /**
     * Tokens per calendar day per account, in one query.
     *
     * @param  Collection<int, Account>  $accounts  the accounts in scope
     * @param  RebalanceWindow  $window  how far back to read
     * @return array<int, array<string, int>> account id => day => tokens
     */
    private function dailyTotalsByAccount(Collection $accounts, RebalanceWindow $window): array
    {
        $rows = Event::query()
            ->whereIn('account_id', $accounts->pluck('id')->all())
            ->when($window->since() !== null, fn ($query) => $query->where('created_at', '>=', $window->since()))
            ->selectRaw('account_id')
            ->selectRaw('DATE(created_at) as day')
            ->selectRaw('SUM(tokens) as tokens')
            ->groupBy('account_id', 'day')
            ->get();

        $daily = [];
        foreach ($rows as $row) {
            $daily[(int) $row->account_id][(string) $row->day] = (int) $row->tokens;
        }

        foreach ($daily as $accountId => $byDay) {
            ksort($byDay);
            $daily[$accountId] = $byDay;
        }

        return $daily;
    }

    /**
     * The heaviest seven consecutive days in a day => tokens series.
     *
     * @param  array<string, int>  $daily  tokens per day, days with no usage absent
     * @return float
     */
    private function peakWindow(array $daily): float
    {
        $windows = $this->allWindows($daily);

        return $windows === [] ? 0.0 : max($windows);
    }

    /**
     * Every rolling seven-day total in the series, idle days counted as
     * zeros so a gap cannot pull two distant busy days into one week.
     *
     * @param  array<string, int>  $daily  tokens per day, oldest first
     * @return array<int, float>
     */
    private function allWindows(array $daily): array
    {
        if ($daily === []) {
            return [];
        }

        $days = array_keys($daily);
        $cursor = Carbon::parse($days[0])->startOfDay();
        $last = Carbon::parse($days[count($days) - 1])->startOfDay();

        $series = [];
        while ($cursor->lessThanOrEqualTo($last)) {
            $series[] = (float) ($daily[$cursor->toDateString()] ?? 0);
            $cursor->addDay();
        }

        $span = min(self::WINDOW_DAYS, count($series));
        $windows = [];
        $running = 0.0;

        foreach ($series as $index => $tokens) {
            $running += $tokens;
            if ($index >= $span) {
                $running -= $series[$index - $span];
            }
            if ($index >= $span - 1) {
                $windows[] = $running;
            }
        }

        return $windows;
    }

    /**
     * Median of the given values, or 0 when there are none.
     *
     * @param  array<int, float>  $values  the values to reduce
     * @return float
     */
    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
