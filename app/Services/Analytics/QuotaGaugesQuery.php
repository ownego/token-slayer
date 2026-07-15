<?php

namespace App\Services\Analytics;

use App\Models\Account;
use App\Services\QuotaProjection;
use Illuminate\Support\Carbon;

/**
 * Builds the current per-account quota gauge rows (5h/7d utilization, reset
 * boundaries, projected-at-reset, near-cap flag) for the analytics page's
 * fleet-quota widget. Reflects live state, so it takes no time filter.
 */
final class QuotaGaugesQuery
{
    /**
     * One quota-gauge row per account, read from its latest snapshot: current
     * 5h/7d utilization, the reset boundaries, the projected utilization at
     * each reset (via {@see QuotaProjection}), and a near-cap flag
     * (`util_7d >= 85`). Accounts never probed report null utilization and
     * are not near-cap.
     *
     * @return array<int, array{account_id:int, email:string, util_5h:?int, util_7d:?int, reset_5h_at:?Carbon, reset_7d_at:?Carbon, projected_5h:?int, projected_7d:?int, near_cap:bool}>
     */
    public function get(): array
    {
        return Account::query()
            ->with('latestUsageSnapshot')
            ->orderBy('email')
            ->get()
            ->map(function (Account $account): array {
                $snapshot = $account->latestUsageSnapshot;

                return [
                    'account_id' => $account->id,
                    'email' => $account->email,
                    'util_5h' => $snapshot?->util_5h,
                    'util_7d' => $snapshot?->util_7d,
                    'reset_5h_at' => $snapshot?->reset_5h_at,
                    'reset_7d_at' => $snapshot?->reset_7d_at,
                    'projected_5h' => $this->project($snapshot?->util_5h, $snapshot?->reset_5h_at, 5, 'hours'),
                    'projected_7d' => $this->project($snapshot?->util_7d, $snapshot?->reset_7d_at, 7, 'days'),
                    'near_cap' => ($snapshot?->util_7d ?? 0) >= 85,
                ];
            })
            ->all();
    }

    /**
     * Project a utilization reading to its reset boundary, or null when the
     * reading or reset is unknown. The window start is the reset minus the
     * window length.
     *
     * @param  ?int  $current  the utilization reading, or null
     * @param  ?Carbon  $resetAt  when the window resets, or null
     * @param  int  $windowLength  the window length magnitude
     * @param  string  $unit  `'hours'` or `'days'`
     * @return ?int the projected percent, or null
     */
    private function project(?int $current, ?Carbon $resetAt, int $windowLength, string $unit): ?int
    {
        if ($current === null || $resetAt === null) {
            return null;
        }

        $windowStart = $unit === 'days'
            ? $resetAt->copy()->subDays($windowLength)
            : $resetAt->copy()->subHours($windowLength);

        return QuotaProjection::projectedAtReset($current, $windowStart, $resetAt, now());
    }
}
