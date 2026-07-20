<?php

namespace App\Services\Analytics;

use App\Enums\MembershipStatus;
use App\Models\Event;
use Illuminate\Database\Query\JoinClause;

/**
 * Contributor breakdown per account: every user with events attributed to an
 * account, their membership status, and their token spend. Powers the member
 * list inside each Fleet Quota card on the admin dashboard. Honors the
 * dashboard's time filter, and can either scope each user's tokens to the
 * account being viewed (default) or show their total across every account.
 */
final class AccountContributorsQuery
{
    /**
     * Build the per-account contributor lists, keyed by account id. Each
     * account's list holds every user with events attributed to it (any
     * membership status), sorted by token spend descending. A user with events
     * but no membership pivot row is reported as untracked.
     *
     * The tokens shown are windowed to `$filters` (all-time when null). When
     * `$totalAcrossAccounts` is true, each user's tokens become their whole
     * footprint in the window — every event of theirs across every account
     * they used, plus usage with no account attribution (private account or
     * un-beaconed) — repeated in each of their cards. That figure can exceed
     * the account's own usage; it answers "how much did this person burn",
     * not "how much landed on this account".
     *
     * @param  ?UsageFilters  $filters  the dashboard time filter, or null for all-time
     * @param  bool  $totalAcrossAccounts  show each user's whole-footprint total instead of the per-account amount
     * @return array<int, array<int, array{user_id:int, handle:string, avatar_url:?string, status:string, tokens:int}>>
     */
    public function get(?UsageFilters $filters = null, bool $totalAcrossAccounts = false): array
    {
        $rows = Event::query()
            ->join('users', 'users.id', '=', 'events.user_id')
            ->leftJoin('account_user', function (JoinClause $join): void {
                $join->on('account_user.account_id', '=', 'events.account_id')
                    ->on('account_user.user_id', '=', 'events.user_id');
            })
            ->whereNotNull('events.account_id')
            ->when($filters !== null, fn ($q) => $q->whereBetween('events.created_at', [$filters->from, $filters->to]))
            ->groupBy('events.account_id', 'users.id', 'users.slack_handle', 'users.display_name', 'users.name', 'users.avatar_url', 'account_user.status')
            ->selectRaw('events.account_id as account_id')
            ->selectRaw('users.id as user_id, users.slack_handle, users.display_name, users.name, users.avatar_url')
            ->selectRaw('account_user.status as status')
            ->selectRaw('SUM(events.tokens) as tokens')
            ->orderByRaw('SUM(events.tokens) DESC')
            ->get();

        $userTotals = $totalAcrossAccounts ? $this->userTotals($filters) : [];

        $byAccount = [];

        foreach ($rows as $row) {
            $userId = (int) $row->user_id;
            $byAccount[(int) $row->account_id][] = [
                'user_id' => $userId,
                'handle' => $row->slack_handle ?: ($row->display_name ?: ($row->name ?: ('#'.$userId))),
                'avatar_url' => $row->avatar_url,
                'status' => $row->status ?? MembershipStatus::Untracked->value,
                'tokens' => $totalAcrossAccounts ? ($userTotals[$userId] ?? 0) : (int) $row->tokens,
            ];
        }

        if ($totalAcrossAccounts) {
            foreach ($byAccount as &$members) {
                usort($members, fn (array $a, array $b): int => $b['tokens'] <=> $a['tokens']);
            }
            unset($members);
        }

        return $byAccount;
    }

    /**
     * Map each account id to its total attributed tokens in the window
     * (all-time when `$filters` is null). This is the real per-account usage —
     * independent of the "total across accounts" display toggle — so the Fleet
     * Quota widget can show a per-account total and a fleet-wide grand total.
     *
     * @param  ?UsageFilters  $filters  the dashboard time filter, or null for all-time
     * @return array<int, int>
     */
    public function accountTotals(?UsageFilters $filters = null): array
    {
        return Event::query()
            ->whereNotNull('events.account_id')
            ->when($filters !== null, fn ($q) => $q->whereBetween('events.created_at', [$filters->from, $filters->to]))
            ->groupBy('events.account_id')
            ->selectRaw('events.account_id as account_id')
            ->selectRaw('SUM(events.tokens) as tokens')
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->account_id => (int) $row->tokens])
            ->all();
    }

    /**
     * Map each user id to their TOTAL tokens in the window: every event that
     * belongs to them, across every account they used AND any usage carrying
     * no account attribution at all (their private account, or un-beaconed
     * usage). Deliberately unfiltered by `account_id` — the toggle reports the
     * person's whole footprint, so this figure can exceed the usage attributed
     * to the account whose card it appears on.
     *
     * @param  ?UsageFilters  $filters  the dashboard time filter, or null for all-time
     * @return array<int, int>
     */
    private function userTotals(?UsageFilters $filters): array
    {
        return Event::query()
            ->when($filters !== null, fn ($q) => $q->whereBetween('events.created_at', [$filters->from, $filters->to]))
            ->groupBy('events.user_id')
            ->selectRaw('events.user_id as user_id')
            ->selectRaw('SUM(events.tokens) as tokens')
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->user_id => (int) $row->tokens])
            ->all();
    }
}
