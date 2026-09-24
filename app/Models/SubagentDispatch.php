<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One currently-tracked subagent presence row behind the battlefield's
 * minion swarm — a pending (agent_id null) or claimed dispatch, aged out by
 * `subagents:sweep-idle` once `last_seen_at` passes `game.subagent_idle_seconds`.
 * See SubagentCountCache for the read/write logic and the migration's
 * docblock for why this replaced a plain cache counter.
 */
class SubagentDispatch extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = ['user_id', 'agent_id', 'last_seen_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }
}
