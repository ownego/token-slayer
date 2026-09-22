<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The Infinity Stone clock for a recognizable boss: it spawns holding a
 * configured opening count, then gains one stone for every configured
 * wall-clock instant (09:30 Asia/Ho_Chi_Minh by default) that passes,
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
        $tick = self::firstTickAfter($spawnedAt);

        while ($count < self::max() && $tick->lessThanOrEqualTo($now)) {
            $count++;
            $tick = $tick->addDay();
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
        if (self::countAt($spawnedAt, $now) >= self::max()) {
            return null;
        }

        return self::firstTickAfter($now);
    }

    /**
     * Wire shape shared by the boot payload and the BossSpawned broadcast.
     *
     * @param  CarbonInterface  $spawnedAt
     * @param  CarbonInterface  $now
     * @return array{stones: int, next_stone_at: string|null}
     */
    public static function state(CarbonInterface $spawnedAt, CarbonInterface $now): array
    {
        return [
            'stones' => self::countAt($spawnedAt, $now),
            'next_stone_at' => self::nextAt($spawnedAt, $now)?->utc()->toIso8601ZuluString(),
        ];
    }

    /**
     * The configured wall-clock instant on the first local day whose tick
     * falls strictly after $at.
     *
     * @param  CarbonInterface  $at
     * @return CarbonImmutable
     */
    private static function firstTickAfter(CarbonInterface $at): CarbonImmutable
    {
        $local = CarbonImmutable::instance($at)->setTimezone(config('game.stones.timezone'));
        $tick = $local->setTime(config('game.stones.hour'), config('game.stones.minute'));

        return $tick->greaterThan($local) ? $tick : $tick->addDay();
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
