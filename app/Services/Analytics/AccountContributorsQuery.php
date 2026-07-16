<?php

namespace App\Services\Analytics;

use App\Enums\MembershipStatus;
use App\Models\Event;
use Illuminate\Database\Query\JoinClause;

/**
 * All-time contributor breakdown per account: every user with events
 * attributed to an account, their membership status, and their total tokens
 * for that account. Powers the member list inside each Fleet Quota card on
 * the admin dashboard, which reflects live state and takes no time filter.
 */
final class AccountContributorsQuery
{
    /**
     * Build the per-account contributor lists, keyed by account id. Each
     * account's list holds every user with events attributed to it (any
     * membership status), sorted by all-time token spend descending. A user
     * with events but no membership pivot row is reported as untracked.
     *
     * @return array<int, array<int, array{user_id:int, handle:string, avatar_url:?string, status:string, tokens:int}>>
     */
    public function get(): array
    {
        $rows = Event::query()
            ->join('users', 'users.id', '=', 'events.user_id')
            ->leftJoin('account_user', function (JoinClause $join): void {
                $join->on('account_user.account_id', '=', 'events.account_id')
                    ->on('account_user.user_id', '=', 'events.user_id');
            })
            ->whereNotNull('events.account_id')
            ->groupBy('events.account_id', 'users.id', 'users.slack_handle', 'users.display_name', 'users.name', 'users.avatar_url', 'account_user.status')
            ->selectRaw('events.account_id as account_id')
            ->selectRaw('users.id as user_id, users.slack_handle, users.display_name, users.name, users.avatar_url')
            ->selectRaw('account_user.status as status')
            ->selectRaw('SUM(events.tokens) as tokens')
            ->orderByRaw('SUM(events.tokens) DESC')
            ->get();

        $byAccount = [];

        foreach ($rows as $row) {
            $byAccount[(int) $row->account_id][] = [
                'user_id' => (int) $row->user_id,
                'handle' => $row->slack_handle ?: ($row->display_name ?: ($row->name ?: ('#'.$row->user_id))),
                'avatar_url' => $row->avatar_url,
                'status' => $row->status ?? MembershipStatus::Untracked->value,
                'tokens' => (int) $row->tokens,
            ];
        }

        return $byAccount;
    }
}
