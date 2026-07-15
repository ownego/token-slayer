<?php

namespace App\Services\Analytics;

use App\Services\Analytics\Concerns\ScopesEventsByFilters;

/**
 * Ranks users by token spend for the analytics page's top-users leaderboard.
 */
final class TopUsersQuery
{
    use ScopesEventsByFilters;

    /**
     * The top users by token spend in the range, honoring the shared filter.
     * The handle falls back through slack handle → display name → name → id.
     *
     * @param  UsageFilters  $filters  the shared analytics filter
     * @param  int  $limit  maximum rows to return
     * @return array<int, array{user_id:int, handle:string, avatar_url:?string, tokens:int}>
     */
    public function get(UsageFilters $filters, int $limit): array
    {
        return $this->scopeEvents($filters)
            ->join('users', 'users.id', '=', 'events.user_id')
            ->groupBy('users.id', 'users.slack_handle', 'users.display_name', 'users.name', 'users.avatar_url')
            ->selectRaw('users.id as user_id, users.slack_handle, users.display_name, users.name, users.avatar_url')
            ->selectRaw('SUM(events.tokens) as tokens')
            ->orderByRaw('SUM(events.tokens) DESC')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => [
                'user_id' => (int) $row->user_id,
                'handle' => $row->slack_handle ?: ($row->display_name ?: ($row->name ?: ('#'.$row->user_id))),
                'avatar_url' => $row->avatar_url,
                'tokens' => (int) $row->tokens,
            ])
            ->all();
    }
}
