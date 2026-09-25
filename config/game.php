<?php

return [
    'base_hp' => (int) env('GAME_BASE_HP', 1_000_000),
    'idle_minutes' => (int) env('GAME_IDLE_MINUTES', 30),

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
