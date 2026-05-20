<?php

namespace App\Events;

use App\Models\Boss;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class HitDealt implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public User $user, public int $damage, public Boss $boss) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('battlefield')];
    }

    public function broadcastAs(): string
    {
        return 'HitDealt';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->user->id,
            'slack_handle' => $this->user->displayHandle(),
            'avatar_url' => $this->user->avatar_url,
            'damage' => $this->damage,
            'boss_id' => $this->boss->id,
            'boss_hp_after' => $this->boss->current_hp,
            'boss_max_hp' => $this->boss->max_hp,
        ];
    }
}
