<?php

test('game config exposes base hp and idle window', function () {
    expect(config('game.base_hp'))->toBe(1_000_000)
        ->and(config('game.idle_minutes'))->toBe(30);
});
