<?php

namespace App\Services\Accounts;

use App\Enums\MembershipStatus;
use App\Models\Event;
use App\Support\CacheKeys;
use Illuminate\Support\Facades\DB;

/**
 * Materializes untracked `account_user` rows for developers who contributed
 * events before membership was recorded on ingest. `insertOrIgnore` protects
 * existing tracked rows (never downgraded) and makes re-runs idempotent.
 */
final class HistoricalMembershipBackfiller
{
    /**
     * Insert an untracked membership row for every distinct (account, user)
     * pair among attributed events that has none yet, then forget the touched
     * accounts' membership caches.
     *
     * @return int the number of rows created
     */
    public function backfill(): int
    {
        $created = 0;
        $touchedAccounts = [];

        Event::query()
            ->whereNotNull('account_id')
            ->select('account_id', 'user_id')
            ->distinct()
            ->orderBy('account_id')
            ->chunk(500, function ($rows) use (&$created, &$touchedAccounts): void {
                foreach ($rows as $row) {
                    $created += DB::table('account_user')->insertOrIgnore([
                        'account_id' => $row->account_id,
                        'user_id' => $row->user_id,
                        'status' => MembershipStatus::Untracked->value,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $touchedAccounts[$row->account_id] = true;
                }
            });

        foreach (array_keys($touchedAccounts) as $accountId) {
            CacheKeys::forgetMembershipPairs((int) $accountId);
            CacheKeys::forgetAccountMembership((int) $accountId);
        }

        return $created;
    }
}
