<?php

namespace App\Services\Analytics;

use App\Services\Analytics\Concerns\ScopesEventsByFilters;

/**
 * Ranks org accounts by token spend for the analytics page's top-accounts
 * leaderboard; events with no account collapse into one "unassigned" row.
 */
final class TopAccountsQuery
{
    use ScopesEventsByFilters;

    /**
     * The top accounts by token spend in the range, honoring the shared
     * filter. Events with no `account_id` collapse into a single
     * "— unassigned —" row.
     *
     * @param  UsageFilters  $filters  the shared analytics filter
     * @param  int  $limit  maximum rows to return
     * @return array<int, array{account_id:?int, email:string, tokens:int}>
     */
    public function get(UsageFilters $filters, int $limit): array
    {
        return $this->scopeEvents($filters)
            ->leftJoin('accounts', 'accounts.id', '=', 'events.account_id')
            ->groupBy('events.account_id', 'accounts.email')
            ->selectRaw('events.account_id as account_id, accounts.email as email')
            ->selectRaw('SUM(events.tokens) as tokens')
            ->orderByRaw('SUM(events.tokens) DESC')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => [
                'account_id' => $row->account_id !== null ? (int) $row->account_id : null,
                'email' => $row->email ?? '— unassigned —',
                'tokens' => (int) $row->tokens,
            ])
            ->all();
    }
}
