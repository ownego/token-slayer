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
     * The quota utilization history for one account within a range, one row
     * per snapshot ordered oldest-first.
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
            ->map(fn ($row): array => [
                'bucket' => $row->created_at->toDateTimeString(),
                'util_5h' => $row->util_5h,
                'util_7d' => $row->util_7d,
            ])
            ->all();
    }
}
