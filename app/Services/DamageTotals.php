<?php

namespace App\Services;

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

final class DamageTotals
{
    private const CACHE_KEY = 'damage-totals:global';

    private const CACHE_TTL_SECONDS = 60;

    /**
     * Community-wide damage across rolling windows. Cached briefly; the
     * battlefield client live-increments between page loads.
     *
     * @return array{allTime:int, monthly:int, daily:int}
     */
    public function global(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, fn (): array => [
            'allTime' => (int) Event::sum('tokens'),
            'monthly' => (int) Event::where('created_at', '>=', now()->subDays(30))->sum('tokens'),
            'daily' => (int) Event::where('created_at', '>=', now()->subDay())->sum('tokens'),
        ]);
    }

    /**
     * One player's damage across the same rolling windows.
     *
     * @return array{allTime:int, monthly:int, daily:int}
     */
    public function forUser(User $user): array
    {
        return [
            'allTime' => (int) $this->forUserQuery($user)->sum('tokens'),
            'monthly' => (int) $this->forUserQuery($user)->where('created_at', '>=', now()->subDays(30))->sum('tokens'),
            'daily' => (int) $this->forUserQuery($user)->where('created_at', '>=', now()->subDay())->sum('tokens'),
        ];
    }

    private function forUserQuery(User $user): \Illuminate\Database\Eloquent\Builder
    {
        return Event::where('user_id', $user->id);
    }
}
