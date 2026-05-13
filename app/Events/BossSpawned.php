<?php

namespace App\Events;

use App\Models\Boss;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

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
        return [
            'boss_id' => $this->boss->id,
            'boss_number' => $this->boss->number,
            'boss_name' => $this->boss->name,
            'max_hp' => $this->boss->max_hp,
        ];
    }
}
