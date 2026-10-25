<?php

namespace App\Events;

use App\Models\Boss;
use App\Models\User;
use App\Services\FighterPositionCache;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FighterCharging implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public User $user, public ?string $activity = null, public ?Boss $boss = null) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('battlefield')];
    }

    public function broadcastAs(): string
    {
        return 'FighterCharging';
    }

    /**
     * Carries the fighter's persisted position so a client that synthesizes
     * a client-side rejoin from this event (see `charge.js`'s
     * `handleCharging` — fires for a fighter not yet present in that
     * client's scene, which happens whenever a `FighterJoined` was missed or
     * the fighter was swept idle) restores it instead of defaulting to the
     * grid. Null when the fighter has never moved.
     *
     * `minion_pool` is the whole pool a subagent summon draws from, with the `rev`
     * that orders it against a later pool: this event can be read before a pool
     * change yet arrive after the `FighterMinionsChanged` that announces it, and the
     * client drops the older one by `rev` (see `isNewerPool()` in `minion-custom.js`).
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->user->id,
            'slack_handle' => $this->user->displayHandle(),
            'avatar_url' => $this->user->avatarProxyUrl(),
            'character' => $this->user->characterForBoss($this->boss?->id),
            'custom_character' => $this->user->customFighterState(),
            'minion_pool' => $this->user->minionPoolState(),
            'activity' => $this->activity,
            'position' => app(FighterPositionCache::class)->get($this->user->id),
        ];
    }
}
