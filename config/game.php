<?php

return [
    'base_hp' => (int) env('GAME_BASE_HP', 1_000_000),
    'idle_minutes' => (int) env('GAME_IDLE_MINUTES', 30),

    // Infinity-stone clock for recognizable bosses (see App\Support\StoneClock):
    // the boss spawns holding `initial` stones, then gains one each time this
    // wall-clock instant passes.
    'stones' => [
        'initial' => (int) env('GAME_STONE_INITIAL', 1),
        'hour' => (int) env('GAME_STONE_HOUR', 9),
        'minute' => (int) env('GAME_STONE_MINUTE', 30),
        'timezone' => env('GAME_STONE_TIMEZONE', 'Asia/Ho_Chi_Minh'),
        'max' => (int) env('GAME_STONE_MAX', 6),
    ],
];
