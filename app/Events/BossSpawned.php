<?php

namespace App\Events;

use App\Models\Boss;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BossSpawned implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public Boss $boss) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('battlefield')];
    }

    public function broadcastAs(): string
    {
        return 'BossSpawned';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $bossId = $this->boss->id;

        $activeFighters = User::where('last_event_at', '>=', now()->subMinutes(config('game.idle_minutes')))
            ->get()
            ->map(fn (User $user) => [
                'user_id' => $user->id,
                'character' => $user->characterForBoss($bossId),
            ])
            ->all();

        return [
            'boss_id' => $bossId,
            'boss_number' => $this->boss->number,
            'boss_name' => $this->boss->name,
            'max_hp' => $this->boss->max_hp,
            'fighters' => $activeFighters,
        ];
    }
}
