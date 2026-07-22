<?php

namespace App\Services\Analytics;

use App\Enums\MembershipStatus;
use App\Models\Event;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

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
     * account's list is the union of its tracked members (so a member appears
     * even with no usage attributed to this account) and any other user with
     * events attributed to the account (reported as their pivot status, or
     * untracked when they have no membership row). Sorted by displayed tokens
     * descending.
     *
     * The tokens shown are windowed to `$filters` (all-time when null). With
     * `$totalAcrossAccounts` false, tokens are the amount attributed to THIS
     * account (0 for a member who contributed nothing here). With it true, a
     * user's tokens become their whole footprint in the window — every event
     * of theirs across every account, plus usage with no account attribution
     * (private account or un-beaconed) — so a provisioned member whose usage is
     * all unattributed still surfaces with their real total. That figure can
     * exceed the account's own usage; it answers "how much did this person
     * burn", not "how much landed on this account".
     *
     * @param  ?UsageFilters  $filters  the dashboard time filter, or null for all-time
     * @param  bool  $totalAcrossAccounts  show each user's whole-footprint total instead of the per-account amount
     * @return array<int, array<int, array{user_id:int, handle:string, avatar_url:?string, status:string, tokens:int}>>
     */
    public function get(?UsageFilters $filters = null, bool $totalAcrossAccounts = false): array
    {
        $eventRows = Event::query()
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
            ->get();

        $memberRows = DB::table('account_user')
            ->join('users', 'users.id', '=', 'account_user.user_id')
            ->where('account_user.status', MembershipStatus::Tracked->value)
            ->select('account_user.account_id', 'account_user.status', 'users.id as user_id', 'users.slack_handle', 'users.display_name', 'users.name', 'users.avatar_url')
            ->get();

        $userTotals = $totalAcrossAccounts ? $this->userTotals($filters) : [];

        // account_id => user_id => entry (with the per-account attributed tokens
        // stashed under `_attributed` until the display value is finalized).
        $byAccount = [];

        // Seed tracked members first so they list even with zero attributed usage.
        foreach ($memberRows as $row) {
            $accountId = (int) $row->account_id;
            $userId = (int) $row->user_id;
            $byAccount[$accountId][$userId] = [
                'user_id' => $userId,
                'handle' => $this->displayHandle($row, $userId),
                'avatar_url' => $row->avatar_url,
                'status' => MembershipStatus::Tracked->value,
                '_attributed' => 0,
            ];
        }

        // Overlay event contributors: set the attributed amount, and add any
        // user who has events here but is not a tracked member.
        foreach ($eventRows as $row) {
            $accountId = (int) $row->account_id;
            $userId = (int) $row->user_id;

            if (isset($byAccount[$accountId][$userId])) {
                $byAccount[$accountId][$userId]['_attributed'] = (int) $row->tokens;

                continue;
            }

            $byAccount[$accountId][$userId] = [
                'user_id' => $userId,
                'handle' => $this->displayHandle($row, $userId),
                'avatar_url' => $row->avatar_url,
                'status' => $row->status ?? MembershipStatus::Untracked->value,
                '_attributed' => (int) $row->tokens,
            ];
        }

        $result = [];

        foreach ($byAccount as $accountId => $members) {
            $list = array_map(function (array $member) use ($totalAcrossAccounts, $userTotals): array {
                return [
                    'user_id' => $member['user_id'],
                    'handle' => $member['handle'],
                    'avatar_url' => $member['avatar_url'],
                    'status' => $member['status'],
                    'tokens' => $totalAcrossAccounts ? ($userTotals[$member['user_id']] ?? 0) : $member['_attributed'],
                ];
            }, array_values($members));

            usort($list, fn (array $a, array $b): int => $b['tokens'] <=> $a['tokens']);

            $result[$accountId] = $list;
        }

        return $result;
    }

    /**
     * Resolve a user's display handle from the available identity columns,
     * falling back to a `#id` label.
     *
     * @param  object  $row  a row carrying slack_handle/display_name/name columns
     * @param  int  $userId  the user's id, for the fallback label
     * @return string
     */
    private function displayHandle(object $row, int $userId): string
    {
        return $row->slack_handle ?: ($row->display_name ?: ($row->name ?: ('#'.$userId)));
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
