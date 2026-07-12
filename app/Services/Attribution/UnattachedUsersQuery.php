<?php

namespace App\Services\Attribution;

use App\Models\User;

/**
 * Lists developers who belong to no org account at all (no `account_user` row of
 * any status) — the "unattached" users surfaced on the Unrecognized page's Users
 * tab. Ordered by most recent activity so the actively-used-but-unmanaged
 * accounts stand out. Returns a framework-free array for direct blade rendering.
 */
final class UnattachedUsersQuery
{
    /**
     * One row per user with no account membership, most-recently-active first.
     *
     * @return array<int, array{user_id:int, handle:string, email:?string, last_event_at:?string, created_at:?string}>
     */
    public function get(): array
    {
        return User::query()
            ->whereDoesntHave('accounts')
            ->orderByRaw('last_event_at IS NULL')
            ->orderByDesc('last_event_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (User $user): array => [
                'user_id' => $user->id,
                'handle' => $user->displayHandle(),
                'email' => $user->email,
                'last_event_at' => $user->last_event_at?->toDateTimeString(),
                'created_at' => $user->created_at?->toDateTimeString(),
            ])
            ->all();
    }
}
