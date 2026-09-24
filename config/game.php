<?php

return [
    'base_hp' => (int) env('GAME_BASE_HP', 1_000_000),
    'idle_minutes' => (int) env('GAME_IDLE_MINUTES', 30),
    // Much shorter than idle_minutes on purpose: a fighter idles for a
    // whole break between turns, but a dispatched subagent going quiet
    // with no event at all is a real signal it's done — see
    // SubagentCountCache's own docblock. Seconds, not minutes: hook v7's
    // agent_id-bearing PreToolUse/PostToolUse give a heartbeat on every
    // tool call a tracked subagent makes (not just its own dispatch/stop),
    // so the window could shrink from the old 3 minutes down to tens of
    // seconds without risking a still-genuinely-busy subagent going stale
    // between heartbeats.
    'subagent_idle_seconds' => (int) env('GAME_SUBAGENT_IDLE_SECONDS', 30),
];
