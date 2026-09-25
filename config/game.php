<?php

return [
    'base_hp' => (int) env('GAME_BASE_HP', 1_000_000),
    'idle_minutes' => (int) env('GAME_IDLE_MINUTES', 30),
    // Much shorter than idle_minutes on purpose: a fighter idles for a
    // whole break between turns, but a dispatched subagent going quiet
    // with no event at all is a real signal it's done — see
    // SubagentCountCache's own docblock. Seconds, not minutes: hook v7's
    // agent_id-bearing PreToolUse/PostToolUse give a heartbeat on every
    // tool call a tracked subagent makes (not just its own dispatch/stop).
    // Raised from 30 to 60 — live 2026-09-25 a real subagent running one
    // tool call longer than 30s (nothing unusual for a build/test/download
    // step) dropped off the battlefield mid-dispatch, well before it was
    // actually done.
    'subagent_idle_seconds' => (int) env('GAME_SUBAGENT_IDLE_SECONDS', 60),

    // Infinity-stone clock for recognizable bosses (see App\Support\StoneClock):
    // the boss spawns holding `initial` stones, then gains one each time this
    // wall-clock tick passes (HH:MM in `timezone`; also the Slack schedule).
    // `max` is deliberately not env-driven: the client mirrors it (STONE_MAX in
    // resources/js/battlefield/boss/scripts/thanos-stones.js).
    'stones' => [
        'initial' => (int) env('GAME_STONE_INITIAL', 1),
        'times' => ['09:30', '14:00', '17:50'],
        'timezone' => 'Asia/Ho_Chi_Minh',
        'max' => 6,
    ],
];
