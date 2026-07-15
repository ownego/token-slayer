<?php

namespace App\Services\Accounts;

use App\Models\Account;
use App\Models\Event;
use App\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;

/**
 * Computes and caches the two expensive per-account membership aggregates the
 * account tabs display: each tracked member's latest event time, and each
 * untracked contributor's event count + latest event time. The member/
 * contributor sets come from `account_user.status`; only these per-user
 * aggregates are cached (forgotten on promote/demote/account-save and Refresh).
 */
final class AccountMembershipCache
{
    /**
     * How long a membership aggregate map is cached before a natural refresh.
     *
     * @var int
     */
    private const int TTL_SECONDS = 3600;

    /**
     * Map each tracked member to the ISO timestamp of their most recent event
     * for this account (null when they have none). Cached per account.
     *
     * @param  Account  $account  the account whose tracked members to aggregate
     * @return array<int, ?string>
     */
    public function trackedLastSeen(Account $account): array
    {
        return Cache::remember(
            CacheKeys::trackedMembers($account->id),
            self::TTL_SECONDS,
            function () use ($account): array {
                $ids = $account->trackedUsers()->pluck('users.id');

                if ($ids->isEmpty()) {
                    return [];
                }

                $lastSeen = Event::query()
                    ->where('account_id', $account->id)
                    ->whereIn('user_id', $ids)
                    ->groupBy('user_id')
                    ->selectRaw('user_id')
                    ->selectRaw('MAX(created_at) as last_seen')
                    ->pluck('last_seen', 'user_id');

                return $ids->mapWithKeys(fn (int $id): array => [$id => $lastSeen[$id] ?? null])->all();
            }
        );
    }

    /**
     * Aggregate each untracked contributor's event count and latest event time
     * for this account. Cached per account.
     *
     * @param  Account  $account  the account whose untracked contributors to aggregate
     * @return array<int, array{events:int, last_seen:?string}>
     */
    public function untrackedAggregates(Account $account): array
    {
        return Cache::remember(
            CacheKeys::untrackedContributors($account->id),
            self::TTL_SECONDS,
            function () use ($account): array {
                $ids = $account->untrackedUsers()->pluck('users.id')->all();

                if ($ids === []) {
                    return [];
                }

                return Event::query()
                    ->where('account_id', $account->id)
                    ->whereIn('user_id', $ids)
                    ->groupBy('user_id')
                    ->selectRaw('user_id')
                    ->selectRaw('COUNT(*) as events')
                    ->selectRaw('MAX(created_at) as last_seen')
                    ->get()
                    ->mapWithKeys(fn ($row): array => [
                        (int) $row->user_id => [
                            'events' => (int) $row->events,
                            'last_seen' => $row->last_seen !== null ? (string) $row->last_seen : null,
                        ],
                    ])
                    ->all();
            }
        );
    }
}
