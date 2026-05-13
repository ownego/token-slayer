<?php

namespace App\Events;

use App\Models\Boss;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BossKilled implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public Boss $boss, public User $killer) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('battlefield')];
    }

    public function broadcastAs(): string
    {
        return 'BossKilled';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'boss_number' => $this->boss->number,
            'boss_name' => $this->boss->name,
            'boss_id' => $this->boss->id,
            'killer_user_id' => $this->killer->id,
            'killer_slack_handle' => $this->killer->slack_handle,
            'killer_avatar_url' => $this->killer->avatar_url,
        ];
    }
}
