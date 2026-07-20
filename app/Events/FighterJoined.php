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
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->user->id,
            'slack_handle' => $this->user->displayHandle(),
            'display_name' => $this->user->display_name,
            'avatar_url' => route('avatar', $this->user),
            'character' => $this->user->characterForBoss($this->boss?->id),
            'position' => app(FighterPositionCache::class)->get($this->user->id),
        ];
    }
}
