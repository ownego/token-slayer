<?php

namespace App\Services\Profile;

use App\Models\Boss;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * A player's boss-kill tally for the fighter sheet — how many killing blows
 * they landed, and the most recent one.
 */
class BossKillCount
{
    /**
     * @param  User  $user
     * @return array{count: int, latest: ?array{name: string, number: int, at: CarbonImmutable}}
     */
    public function for(User $user): array
    {
        $kills = Boss::query()
            ->where('killing_blow_user_id', $user->id)
            ->orderByDesc('defeated_at')
            ->get(['name', 'number', 'defeated_at']);

        $latest = $kills->first();

        return [
            'count' => $kills->count(),
            'latest' => $latest ? ['name' => $latest->name, 'number' => $latest->number, 'at' => $latest->defeated_at] : null,
        ];
    }
}
