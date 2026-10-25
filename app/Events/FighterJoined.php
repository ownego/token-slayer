<?php

namespace App\Events;

use App\Models\Boss;
use App\Models\User;
use App\Services\FighterPositionCache;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FighterJoined implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public User $user, public ?Boss $boss = null) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('battlefield')];
    }

    public function broadcastAs(): string
    {
        return 'FighterJoined';
    }

    /**
     * Carries the fighter's persisted position so a rejoining fighter lands
     * back where they left off on every client already watching the
     * battlefield, instead of snapping to the default grid row. Null when the
     * fighter has never moved.
     *
     * `minion_pool` is the whole pool a subagent summon draws from, with the `rev`
     * that orders it against a later pool (see `MinionPool::get()`). It is read from
     * the user's minions relation, so it costs no query beyond the one load that
     * `custom_character` shares with it for the request.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->user->id,
            'slack_handle' => $this->user->displayHandle(),
            'display_name' => $this->user->display_name,
            'avatar_url' => $this->user->avatarProxyUrl(),
            'character' => $this->user->characterForBoss($this->boss?->id),
            'custom_character' => $this->user->customFighterState(),
            'minion_pool' => $this->user->minionPoolState(),
            'position' => app(FighterPositionCache::class)->get($this->user->id),
        ];
    }
}
