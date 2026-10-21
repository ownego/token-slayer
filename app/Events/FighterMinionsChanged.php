<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcasts a user's whole set of custom minions after it changes (one added or
 * removed), so every client already watching the battlefield swaps the minions that
 * fighter's subagents summon, instead of waiting for the fighter's next
 * FighterJoined/FighterCharging.
 */
class FighterMinionsChanged implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /**
     * The user whose minion set changed.
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
        return 'FighterMinionsChanged';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->user->id,
            'custom_minions' => $this->user->customMinionsState(),
        ];
    }
}
