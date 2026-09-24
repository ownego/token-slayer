<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Reports a fighter's current count of dispatched-but-not-yet-stopped
 * subagents, driving the battlefield's cosmetic minion swarm. Cosmetic-only —
 * never carries or affects damage.
 */
class FighterAgentCountChanged implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /**
     * @param  User  $user
     * @param  int  $count
     * @param  int  $seq  monotonic per-user sequence number (SubagentCountCache::nextSeq) — lets the client discard a broadcast that arrives after a fresher one it already applied, since near-simultaneous concurrent dispatches have no guaranteed delivery order over the wire
     */
    public function __construct(public User $user, public int $count, public int $seq) {}

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
        return 'FighterAgentCountChanged';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->user->id,
            'count' => $this->count,
            'seq' => $this->seq,
        ];
    }
}
