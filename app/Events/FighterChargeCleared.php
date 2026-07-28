<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Clears a fighter's charging visual without removing it from the
 * battlefield. Dispatched whenever a turn ends with nothing to damage the
 * boss with (e.g. a Stop event that resolves to zero tokens, common for
 * cross-machine hook setups) — distinct from `FighterIdled`, which the
 * client treats as a full removal and is reserved for the genuine
 * sweep-idle path.
 */
class FighterChargeCleared implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /**
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
        return 'FighterChargeCleared';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->user->id,
        ];
    }
}
