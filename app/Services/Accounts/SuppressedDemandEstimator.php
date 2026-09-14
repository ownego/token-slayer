<?php

namespace App\Services\Accounts;

use App\Models\Account;
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
     * @param  ClosedQuotaWindows  $windows  where one quota week ends and the next begins
     * @return void
     */
    public function __construct(private readonly ClosedQuotaWindows $windows) {}

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
     * Utilisation at which the account stopped serving its users rather than
     * merely worrying them.
     *
     * Crossing {@see RAMP_PERCENT} is where a week's rate stops being honest;
     * it is not where the account died. Reporting the two as one number told
     * an admin that six of seven accounts "ran out mid-week and rationed
     * their own users" when one of them had never been past 82% of its quota
     * in a month, and a purchase was being argued from that count.
     *
     * @var int
     */
    private const int RAN_OUT_PERCENT = 95;

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
     * @return array<int, array{saturated: bool, ran_out: bool, days_to_ramp: float|null, projected_percent: float|null}>
     */
    public function measure(Collection $accounts, RebalanceWindow $window): array
    {
        $measured = [];

        foreach ($accounts as $account) {
            $curves = $this->windows->curves($account, $window);
            $projections = [];
            $ramps = [];
            $ranOut = false;

            foreach ($curves as $curve) {
                if (max(array_column($curve, 'util')) >= self::RAN_OUT_PERCENT) {
                    $ranOut = true;
                }

                $ramp = $this->rampPoint($curve);
                if ($ramp === null) {
                    continue; // never got near the ceiling; nothing was held back
                }

                $ramps[] = $ramp['days'];
                $projections[] = $ramp['util'] / $ramp['days'] * self::WINDOW_DAYS;
            }

            if ($projections === []) {
                if ($curves !== []) {
                    $measured[$account->id] = ['saturated' => false, 'ran_out' => false, 'days_to_ramp' => null, 'projected_percent' => null];
                }

                continue;
            }

            $measured[$account->id] = [
                'saturated' => true,
                'ran_out' => $ranOut,
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
     * @param  array<int, array{at: Carbon, util: int, reset_at: Carbon, opened_at: Carbon}>  $curve  the window's readings, oldest first
     * @return array{days: float, util: float}|null
     */
    private function rampPoint(array $curve): ?array
    {
        foreach ($curve as $point) {
            if ($point['util'] < self::RAMP_PERCENT) {
                continue;
            }

            $days = $point['opened_at']->diffInSeconds($point['at'], absolute: true) / 86400;

            return $days > 0.0 ? ['days' => $days, 'util' => (float) $point['util']] : null;
        }

        return null;
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
