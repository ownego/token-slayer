<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The Infinity Stone clock for a recognizable boss: it spawns holding a
 * configured opening count, then gains one stone for every configured
 * wall-clock tick (09:30, 14:00 and 17:50 Asia/Ho_Chi_Minh) that passes,
 * capped at a maximum.
 *
 * Pure: callers pass "now", so the arithmetic is testable and the client can
 * continue it on its own clock from the two values state() puts on the wire.
 */
final class StoneClock
{
    /**
     * Stone names in the order the sockets fill, matching STONE_COLORS in
     * resources/js/battlefield/boss/scripts/thanos-stones.js.
     *
     * @var array<int, string>
     */
    public const array NAMES = ['Power', 'Space', 'Reality', 'Soul', 'Time', 'Mind'];

    /**
     * Human name of the Nth stone (1-based), e.g. "Space Stone" for 2.
     *
     * @param  int  $ordinal
     * @return string
     */
    public static function nameOf(int $ordinal): string
    {
        return (self::NAMES[$ordinal - 1] ?? "#{$ordinal}").' Stone';
    }

    /**
     * Stones held at $now: the opening count plus one per tick strictly
     * after $spawnedAt and up to $now, capped.
     *
     * @param  CarbonInterface  $spawnedAt
     * @param  CarbonInterface  $now
     * @return int
     */
    public static function countAt(CarbonInterface $spawnedAt, CarbonInterface $now): int
    {
        $count = min((int) config('game.stones.initial'), self::max());

        foreach (self::ticksAfter($spawnedAt, self::max() - $count) as $tick) {
            if ($tick->greaterThan($now)) {
                break;
            }
            $count++;
        }

        return $count;
    }

    /**
     * The first tick strictly after $now, or null once the cap is reached.
     *
     * @param  CarbonInterface  $spawnedAt
     * @param  CarbonInterface  $now
     * @return CarbonImmutable|null
     */
    public static function nextAt(CarbonInterface $spawnedAt, CarbonInterface $now): ?CarbonImmutable
    {
        return self::scheduleAt($spawnedAt, $now)[0] ?? null;
    }

    /**
     * Every tick still to come, one per stone the boss has yet to earn,
     * earliest first; empty once the cap is reached.
     *
     * @param  CarbonInterface  $spawnedAt
     * @param  CarbonInterface  $now
     * @return array<int, CarbonImmutable>
     */
    public static function scheduleAt(CarbonInterface $spawnedAt, CarbonInterface $now): array
    {
        return self::ticksAfter($now, self::max() - self::countAt($spawnedAt, $now));
    }

    /**
     * Wire shape shared by the boot payload and the BossSpawned broadcast.
     * The schedule is a comma-joined list of UTC instants so the payload
     * stays scalar; the client only counts how many have passed, so it needs
     * no calendar or timezone logic of its own.
     *
     * @param  CarbonInterface  $spawnedAt
     * @param  CarbonInterface  $now
     * @return array{stones: int, stone_schedule: string}
     */
    public static function state(CarbonInterface $spawnedAt, CarbonInterface $now): array
    {
        return [
            'stones' => self::countAt($spawnedAt, $now),
            'stone_schedule' => implode(',', array_map(
                fn (CarbonImmutable $tick) => $tick->utc()->toIso8601ZuluString(),
                self::scheduleAt($spawnedAt, $now),
            )),
        ];
    }

    /**
     * The next $limit configured wall-clock ticks strictly after $at,
     * earliest first.
     *
     * @param  CarbonInterface  $at
     * @param  int  $limit
     * @return array<int, CarbonImmutable>
     */
    private static function ticksAfter(CarbonInterface $at, int $limit): array
    {
        $times = config('game.stones.times');

        if ($times === []) {
            return []; // no ticks configured: the clock never advances
        }

        sort($times);
        $day = CarbonImmutable::instance($at)->setTimezone(config('game.stones.timezone'))->startOfDay();
        $ticks = [];

        while (count($ticks) < $limit) {
            foreach ($times as $time) {
                [$hour, $minute] = array_map('intval', explode(':', $time));
                $tick = $day->setTime($hour, $minute);
                if ($tick->greaterThan($at) && count($ticks) < $limit) {
                    $ticks[] = $tick;
                }
            }
            $day = $day->addDay();
        }

        return $ticks;
    }

    /**
     * Maximum number of stones a boss can hold.
     *
     * @return int
     */
    private static function max(): int
    {
        return config('game.stones.max');
    }
}
