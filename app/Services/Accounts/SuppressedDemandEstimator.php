<?php

namespace App\Services\Accounts;

use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * How much more an account would have served if it had not run out.
 *
 * Everything else on this page measures tokens people actually spent, and
 * that measurement is censored: once an account nears its ceiling people
 * throttle themselves, switch to a cheaper model, or simply stop. A week
 * that burns 80% of the quota in three days and then crawls to 100% did not
 * meet demand — it capped it, and reading the total as "what the team
 * needed" understates them by a wide margin.
 *
 * The rise before the ceiling is the honest part of the curve. Projecting
 * that rate across the full week says what the account would have delivered
 * with room to run, and the gap against what it actually delivered is the
 * demand that was suppressed.
 */
final class SuppressedDemandEstimator
{
    /**
     * Utilisation at which a week stops being free-running. Below this the
     * curve reflects what people wanted; at and above it, behaviour starts
     * bending around the ceiling, so the rate is measured up to here and no
     * further.
     *
     * @var int
     */
    private const int RAMP_PERCENT = 80;

    /**
     * The quota window length these percentages are against.
     *
     * @var int
     */
    private const int WINDOW_DAYS = 7;

    /**
     * Per account, what a full week at its free-running rate would have come
     * to. Accounts that never approached their ceiling are reported as
     * unsuppressed with no projection, because there is nothing to correct
     * and inventing a figure would inflate a fleet behaving perfectly well.
     *
     * @param  Collection<int, Account>  $accounts  the accounts to read
     * @param  RebalanceWindow  $window  how far back to read snapshots
     * @return array<int, array{saturated: bool, days_to_ramp: float|null, projected_percent: float|null}>
     */
    public function measure(Collection $accounts, RebalanceWindow $window): array
    {
        $measured = [];

        foreach ($accounts as $account) {
            $projections = [];
            $ramps = [];

            foreach ($this->closedWindows($account, $window) as $curve) {
                $ramp = $this->rampPoint($curve);
                if ($ramp === null) {
                    continue; // never got near the ceiling; nothing was held back
                }

                $ramps[] = $ramp['days'];
                $projections[] = $ramp['util'] / $ramp['days'] * self::WINDOW_DAYS;
            }

            if ($projections === []) {
                if ($this->closedWindows($account, $window) !== []) {
                    $measured[$account->id] = ['saturated' => false, 'days_to_ramp' => null, 'projected_percent' => null];
                }

                continue;
            }

            $measured[$account->id] = [
                'saturated' => true,
                'days_to_ramp' => $this->median($ramps),
                'projected_percent' => $this->median($projections),
            ];
        }

        return $measured;
    }

    /**
     * The first moment a window crossed {@see RAMP_PERCENT}, as days since
     * the window opened and the utilisation reached — or null if it never
     * got there.
     *
     * @param  array<int, array{at: Carbon, util: int, opened: Carbon}>  $curve  the window's readings, oldest first
     * @return array{days: float, util: float}|null
     */
    private function rampPoint(array $curve): ?array
    {
        foreach ($curve as $point) {
            if ($point['util'] < self::RAMP_PERCENT) {
                continue;
            }

            $days = $point['opened']->diffInSeconds($point['at'], absolute: true) / 86400;

            return $days > 0.0 ? ['days' => $days, 'util' => (float) $point['util']] : null;
        }

        return null;
    }

    /**
     * Every closed quota window's utilisation curve, oldest reading first.
     * A window still open is skipped: its curve has not finished, and the
     * rate so far says nothing about where it would have ended.
     *
     * @param  Account  $account  the account to read
     * @param  RebalanceWindow  $window  how far back to read
     * @return array<int, array<int, array{at: Carbon, util: int, opened: Carbon}>>
     */
    private function closedWindows(Account $account, RebalanceWindow $window): array
    {
        $rows = AccountUsageSnapshot::query()
            ->where('account_id', $account->id)
            ->whereNotNull('reset_7d_at')
            ->whereNotNull('util_7d')
            ->when($window->since() !== null, fn ($query) => $query->where('created_at', '>=', $window->since()))
            ->orderBy('created_at')
            ->get(['util_7d', 'reset_7d_at', 'created_at']);

        $curves = [];

        foreach ($rows as $row) {
            $resetAt = Carbon::parse($row->reset_7d_at)->startOfHour();
            if ($resetAt->isFuture()) {
                continue;
            }

            $curves[$resetAt->toDateTimeString()][] = [
                'at' => Carbon::parse($row->created_at),
                'util' => (int) $row->util_7d,
                'opened' => $resetAt->copy()->subDays(self::WINDOW_DAYS),
            ];
        }

        return array_values($curves);
    }

    /**
     * Median of the given values — one freak week should not set the figure
     * every capacity decision is measured against.
     *
     * @param  array<int, float>  $values  the values to reduce
     * @return float
     */
    private function median(array $values): float
    {
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
