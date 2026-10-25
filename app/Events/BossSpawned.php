<?php

namespace App\Events;

use App\Enums\BossCharacter;
use App\Models\Boss;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Announces the boss that replaces one just killed, with the character each active
 * fighter wears against it.
 *
 * It deliberately carries no minion pool. It repeats every active fighter, and the
 * hook path dispatches it through `rescue()`, so a pool per row (up to three minions,
 * each with a `url_base` that is a full URL on a bucket disk) could push the request
 * past Reverb's `max_request_size` of 10,000 bytes and silently drop the spawn. A
 * client keeps the pool it already holds; `FighterJoined`, `FighterCharging`,
 * `FighterMinionsChanged`, the boot payload, `/api/state` and the resync carry it.
 */
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

        $activeFighters = User::with('customCharacter')
            ->where('last_event_at', '>=', now()->subMinutes(config('game.idle_minutes')))
            ->get()
            ->map(fn (User $user) => [
                'user_id' => $user->id,
                'character' => $user->characterForBoss($bossId),
                'custom_character' => $user->customFighterState(),
            ])
            ->all();

        return [
            'boss_id' => $bossId,
            'boss_number' => $this->boss->number,
            'boss_name' => $this->boss->name,
            'max_hp' => $this->boss->max_hp,
            'fighters' => $activeFighters,
            ...(BossCharacter::of($this->boss)?->scriptState($this->boss) ?? []),
        ];
    }
}
