<?php

namespace App\Services\Profile;

use App\Models\Account;
use App\Models\Event;
use App\Services\Accounts\AccountMemberStatusQuery;
use Carbon\CarbonImmutable;

/**
 * {@see AccountMemberStatusQuery}'s member list, each row joined with the
 * member's own damage/events dealt today and their real last-active
 * timestamp (not scoped to today — a member last active yesterday still
 * shows that date, never "never") — for the fighter sheet's members table.
 * Damage/events are always summed fresh from `events` (never a mutable
 * counter), scoped to this account so a member's usage on a different
 * account never leaks into this row.
 */
class AccountMemberActivity
{
    /**
     * The membership-list query this service enriches with activity.
     *
     * @param  AccountMemberStatusQuery  $members
     * @return void
     */
    public function __construct(private readonly AccountMemberStatusQuery $members) {}

    /**
     * The account's members, each joined with today's damage/events and
     * their real last-active timestamp.
     *
     * @param  Account  $account
     * @return array<int, array{user_id: int, handle: string, status: string, damage_today: int, events_today: int, last_seen_at: ?CarbonImmutable}>
     */
    public function for(Account $account): array
    {
        $members = $this->members->get($account);

        if ($members === []) {
            return [];
        }

        $userIds = array_column($members, 'user_id');
        $baseQuery = fn () => Event::query()->where('account_id', $account->id)->whereIn('user_id', $userIds);

        // A day boundary, not whereDate('created_at', ...): whereDate()
        // compiles to created_at::date = ?, which can't use the
        // (account_id, created_at) index and forces a full scan of the
        // account's whole event history on every render.
        $today = $baseQuery()
            ->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])
            ->selectRaw('user_id, SUM(tokens) as damage_today, COUNT(*) as events_today')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        // last_seen_at is a member's real last-active time, not scoped to
        // today — a member last active yesterday must show that date, not
        // "never" (today's own query has no row for them at all).
        $lastSeen = $baseQuery()
            ->selectRaw('user_id, MAX(created_at) as last_seen_at')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        return array_map(function (array $member) use ($today, $lastSeen): array {
            $todayRow = $today->get($member['user_id']);
            $lastSeenAt = $lastSeen->get($member['user_id'])?->last_seen_at;

            return [
                ...$member,
                'damage_today' => (int) ($todayRow->damage_today ?? 0),
                'events_today' => (int) ($todayRow->events_today ?? 0),
                'last_seen_at' => $lastSeenAt ? CarbonImmutable::parse($lastSeenAt) : null,
            ];
        }, $members);
    }
}
