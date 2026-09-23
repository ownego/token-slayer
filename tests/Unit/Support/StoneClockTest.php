<?php

use App\Support\StoneClock;
use Carbon\CarbonImmutable;

// 09:30 Asia/Ho_Chi_Minh is 02:30 UTC; Vietnam has no DST so the offset never moves.
const SPAWN = '2026-09-21T03:00:00Z'; // 10:00 local, half an hour after that day's 09:30

test('spawns holding one stone, then earns one per 09:30 that has passed', function (string $now, int $expected) {
    expect(StoneClock::countAt(CarbonImmutable::parse(SPAWN), CarbonImmutable::parse($now)))->toBe($expected);
})->with([
    'the moment it spawns' => [SPAWN, 1],
    'same day, before next 09:30' => ['2026-09-21T11:00:00Z', 1],
    'one second before 09:30' => ['2026-09-22T02:29:59Z', 1],
    'exactly 09:30 next day' => ['2026-09-22T02:30:00Z', 2],
    'three mornings later' => ['2026-09-24T05:00:00Z', 4],
    'five mornings later completes the gauntlet' => ['2026-09-26T02:30:00Z', 6],
    'ten mornings later is capped at six' => ['2026-10-01T02:30:00Z', 6],
]);

test('a boss spawned a minute before 09:30 has two stones at that 09:30', function () {
    $spawn = CarbonImmutable::parse('2026-09-22T02:29:00Z');

    expect(StoneClock::countAt($spawn, CarbonImmutable::parse('2026-09-22T02:30:00Z')))->toBe(2);
});

test('a boss spawned exactly at 09:30 does not count that same instant', function () {
    $spawn = CarbonImmutable::parse('2026-09-22T02:30:00Z');

    expect(StoneClock::countAt($spawn, $spawn))->toBe(1)
        ->and(StoneClock::countAt($spawn, CarbonImmutable::parse('2026-09-23T02:30:00Z')))->toBe(2);
});

test('names exactly one stone per socket, so the cap is fixed by the roster of names', function () {
    expect(StoneClock::NAMES)->toHaveCount(config('game.stones.max'));
});

test('the opening stone count comes from config', function () {
    config(['game.stones.initial' => 0]);

    expect(StoneClock::countAt(CarbonImmutable::parse(SPAWN), CarbonImmutable::parse(SPAWN)))->toBe(0);
});

test('nextAt is the first 09:30 after now, or null once the cap is reached', function () {
    $spawn = CarbonImmutable::parse(SPAWN);

    expect(StoneClock::nextAt($spawn, CarbonImmutable::parse('2026-09-21T11:00:00Z'))?->toIso8601ZuluString())
        ->toBe('2026-09-22T02:30:00Z')
        ->and(StoneClock::nextAt($spawn, CarbonImmutable::parse('2026-09-22T02:30:00Z'))?->toIso8601ZuluString())
        ->toBe('2026-09-23T02:30:00Z')
        ->and(StoneClock::nextAt($spawn, CarbonImmutable::parse('2026-09-26T02:30:00Z')))
        ->toBeNull();
});

test('state packs the count and the next instant as a UTC ISO string for the wire', function () {
    $state = StoneClock::state(CarbonImmutable::parse(SPAWN), CarbonImmutable::parse('2026-09-23T10:00:00Z'));

    expect($state)->toBe(['stones' => 3, 'next_stone_at' => '2026-09-24T02:30:00Z']);
});
