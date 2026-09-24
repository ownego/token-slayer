<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Durable per-user presence rows behind the battlefield's subagent
     * minion swarm — replaces the old SubagentCountCache raw increment/
     * decrement counter, which trusted every "stop"-shaped hook event as a
     * genuine "this subagent is done" signal. Verified live 2026-09-23: a
     * single subagent can fire multiple such events over its own lifetime
     * whenever the harness backgrounds one of ITS tool calls, so a plain
     * counter silently under-counted a burst of dispatches to zero long
     * before any of them were actually done. A row here instead tracks one
     * subagent's own last-seen activity, mirroring how `users.last_event_at`
     * + fighters:sweep-idle already track a whole session's presence — a row
     * only disappears after `game.subagent_idle_seconds` with no event at
     * all, not the instant any single event arrives.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('subagent_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Null until the first event carrying a real agent_id claims
            // this row (see SubagentCountCache::recordActivity) — a fresh
            // Task/Agent dispatch has no agent_id yet, only PreToolUse's
            // tool_name signaling "something got dispatched".
            $table->string('agent_id')->nullable();
            $table->timestamp('last_seen_at')->index();
            $table->timestamps();

            $table->unique(['user_id', 'agent_id']);
            $table->index('user_id');
        });
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('subagent_dispatches');
    }
};
