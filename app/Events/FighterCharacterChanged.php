<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcasts a user's newly equipped fighter character so every client
 * already watching the battlefield re-skins that fighter's live sprite,
 * instead of waiting for their next FighterJoined/BossSpawned appearance.
 */
class FighterCharacterChanged implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /**
     * The user who just equipped a character.
     *
     * @param  User  $user
     */
    public function __construct(public User $user) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('battlefield')];
    }

    /**
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'FighterCharacterChanged';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->user->id,
            'character' => $this->user->characterForBoss(null),
        ];
    }
}
