<?php

namespace App\Services\Accounts;

use App\Models\Account;
use App\Models\Event;
use App\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;

/**
 * Computes and caches the two expensive per-account membership aggregates the
 * Members tab displays: each tracked member's event count + latest event
 * time, and each untracked contributor's event count + latest event time. The
 * member/contributor sets come from `account_user.status`; only these
 * per-user aggregates are cached (forgotten on promote/demote/account-save
 * and Refresh).
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
     * Aggregate each tracked member's event count and latest event time for
     * this account. Cached per account.
     *
     * @param  Account  $account  the account whose tracked members to aggregate
     * @return array<int, array{events:int, last_seen:?string}>
     */
    public function trackedAggregates(Account $account): array
    {
        return Cache::remember(
            CacheKeys::trackedMembers($account->id),
            self::TTL_SECONDS,
            function () use ($account): array {
                $ids = $account->trackedUsers()->pluck('users.id')->all();

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

    /**
     * Aggregate every contributor's (any status) event count and latest event
     * time for this account, keyed by user id. Merges the two per-status
     * caches so a caller doesn't need to know which status a user has.
     *
     * @param  Account  $account  the account whose contributors to aggregate
     * @return array<int, array{events:int, last_seen:?string}>
     */
    public function allContributorAggregates(Account $account): array
    {
        // `+` (array union), not array_merge/spread: those renumber integer
        // keys, which would collapse distinct user ids into positional 0,1,2…
        return $this->trackedAggregates($account) + $this->untrackedAggregates($account);
    }
}
