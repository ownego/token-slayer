<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Reports one specific dispatched subagent starting or finishing a tool
 * call, driving the battlefield's per-minion "using a tool right now"
 * visual (a lit-up ring on the one minion sprite the client has assigned to
 * this agent_id, not the whole swarm). Cosmetic-only — never carries or
 * affects damage.
 */
class FighterAgentToolUsed implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /**
     * @param  User  $user  the fighter whose dispatched subagent this is
     * @param  string  $agentId  identifies the one subagent this signal is about, stable across its own PreToolUse/PostToolUse pairs
     * @param  bool  $busy  true from PreToolUse (a tool call just started), false from PostToolUse (it just finished)
     */
    public function __construct(public User $user, public string $agentId, public bool $busy) {}

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
        return 'FighterAgentToolUsed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->user->id,
            'agent_id' => $this->agentId,
            'busy' => $this->busy,
        ];
    }
}
