<?php

use App\Support\StoneClock;
use Carbon\CarbonImmutable;

// Ticks are 09:30, 14:00 and 17:50 Asia/Ho_Chi_Minh = 02:30, 07:00 and 10:50 UTC.
const SPAWN = '2026-09-21T03:00:00Z'; // 10:00 local, after that day's 09:30

test('spawns holding one stone, then earns one per tick that has passed', function (string $now, int $expected) {
    expect(StoneClock::countAt(CarbonImmutable::parse(SPAWN), CarbonImmutable::parse($now)))->toBe($expected);
})->with([
    'the moment it spawns' => [SPAWN, 1],
    'one second before 14:00' => ['2026-09-21T06:59:59Z', 1],
    'exactly 14:00' => ['2026-09-21T07:00:00Z', 2],
    'after 17:50 the same day' => ['2026-09-21T11:00:00Z', 3],
    'overnight earns nothing' => ['2026-09-22T01:00:00Z', 3],
    'next 09:30' => ['2026-09-22T02:30:00Z', 4],
    'next 17:50 completes the gauntlet' => ['2026-09-22T10:50:00Z', 6],
    'a week later is capped at six' => ['2026-09-28T10:50:00Z', 6],
]);

test('a boss spawned a minute before a tick has two stones at that tick', function () {
    $spawn = CarbonImmutable::parse('2026-09-22T02:29:00Z');

    expect(StoneClock::countAt($spawn, CarbonImmutable::parse('2026-09-22T02:30:00Z')))->toBe(2);
});

test('a boss spawned exactly on a tick does not count that same instant', function () {
    $spawn = CarbonImmutable::parse('2026-09-22T02:30:00Z');

    expect(StoneClock::countAt($spawn, $spawn))->toBe(1)
        ->and(StoneClock::countAt($spawn, CarbonImmutable::parse('2026-09-22T07:00:00Z')))->toBe(2);
});

test('names exactly one stone per socket, so the cap is fixed by the roster of names', function () {
    expect(StoneClock::NAMES)->toHaveCount(config('game.stones.max'));
});

test('the opening stone count comes from config', function () {
    config(['game.stones.initial' => 0]);

    expect(StoneClock::countAt(CarbonImmutable::parse(SPAWN), CarbonImmutable::parse(SPAWN)))->toBe(0);
});

test('configured times are honoured in clock order whatever order they are listed in', function () {
    config(['game.stones.times' => ['17:50', '09:30', '14:00']]);

    expect(StoneClock::nextAt(CarbonImmutable::parse(SPAWN), CarbonImmutable::parse(SPAWN))?->toIso8601ZuluString())
        ->toBe('2026-09-21T07:00:00Z');
});

test('an empty tick list freezes the clock at the opening count instead of looping forever', function () {
    config(['game.stones.times' => []]);
    $spawn = CarbonImmutable::parse(SPAWN);

    expect(StoneClock::countAt($spawn, CarbonImmutable::parse('2026-09-28T00:00:00Z')))->toBe(1)
        ->and(StoneClock::nextAt($spawn, $spawn))->toBeNull()
        ->and(StoneClock::state($spawn, $spawn))->toBe(['stones' => 1, 'stone_schedule' => '']);
});

test('nextAt is the first tick after now, or null once the cap is reached', function () {
    $spawn = CarbonImmutable::parse(SPAWN);

    expect(StoneClock::nextAt($spawn, CarbonImmutable::parse('2026-09-21T07:00:00Z'))?->toIso8601ZuluString())
        ->toBe('2026-09-21T10:50:00Z')
        ->and(StoneClock::nextAt($spawn, CarbonImmutable::parse('2026-09-21T11:00:00Z'))?->toIso8601ZuluString())
        ->toBe('2026-09-22T02:30:00Z')
        ->and(StoneClock::nextAt($spawn, CarbonImmutable::parse('2026-09-22T10:50:00Z')))
        ->toBeNull();
});

test('state packs the count and every remaining tick as one comma-joined UTC string for the wire', function () {
    $state = StoneClock::state(CarbonImmutable::parse(SPAWN), CarbonImmutable::parse('2026-09-21T11:00:00Z'));

    expect($state)->toBe([
        'stones' => 3,
        'stone_schedule' => '2026-09-22T02:30:00Z,2026-09-22T07:00:00Z,2026-09-22T10:50:00Z',
    ]);
});

test('a complete gauntlet has an empty schedule', function () {
    $state = StoneClock::state(CarbonImmutable::parse(SPAWN), CarbonImmutable::parse('2026-09-28T00:00:00Z'));

    expect($state)->toBe(['stones' => 6, 'stone_schedule' => '']);
});
