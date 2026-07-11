<?php

namespace App\Services\Analytics;

use App\Models\Account;
use Illuminate\Support\Carbon;

/**
 * Reads one account's quota utilization history (the sawtooth series) for the
 * per-account drill-down chart.
 */
final class AccountQuotaHistoryQuery
{
    /**
     * The quota utilization history for one account within a range, bucketed
     * to one point per hour (the last reading in each hour) and ordered
     * oldest-first. Hourly buckets keep the 7-day chart readable — the raw
     * ~5-minute probe cadence produces far too many points.
     *
     * @param  Account  $account  the account whose history is read
     * @param  Carbon  $from  inclusive start
     * @param  Carbon  $to  inclusive end
     * @return array<int, array{bucket:string, util_5h:?int, util_7d:?int}>
     */
    public function get(Account $account, Carbon $from, Carbon $to): array
    {
        return $account->usageSnapshots()
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->get(['created_at', 'util_5h', 'util_7d'])
            ->groupBy(fn ($row): string => $row->created_at->format('Y-m-d H:00'))
            ->map(fn ($hour): array => [
                'bucket' => $hour->last()->created_at->format('Y-m-d H:00'),
                'util_5h' => $hour->last()->util_5h,
                'util_7d' => $hour->last()->util_7d,
            ])
            ->values()
            ->all();
    }
}
