<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcasts a user's whole minion pool after it changes (a look ticked or unticked,
 * a minion added, replaced or removed, or one taken down by an admin), so every client
 * already watching the battlefield swaps the minions that fighter's subagents summon,
 * instead of waiting for the fighter's next FighterJoined/FighterCharging.
 *
 * The pool carries a `rev` that grows with every change. A FighterCharging built from
 * the pool before this change can still arrive after it, so a client keeps whichever
 * pool has the higher `rev`.
 */
class FighterMinionsChanged implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /**
     * Keep the user whose pool changed.
     *
     * @param  User  $user  the user whose minion pool changed
     */
    public function __construct(public User $user) {}

    /**
     * The channel every battlefield client listens on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('battlefield')];
    }

    /**
     * The event name Echo listens for; it must equal the `ECHO_EVENT_MAP` key.
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'FighterMinionsChanged';
    }

    /**
     * The user and their pool: `{rev, defaults, custom}`, the same shape the other
     * events, the boot payload and `/api/state` carry under `minion_pool`.
     *
     * The pool is read from a fresh copy of the user, never from the instance the
     * caller held while it changed the pool: that instance can still hold the
     * minions it loaded before the change, and announcing those would hand every
     * client the pool that was just replaced under the new `rev`.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $user = $this->user->fresh(['customMinions']) ?? $this->user;

        return [
            'user_id' => $this->user->id,
            'minion_pool' => $user->minionPoolState(),
        ];
    }
}
