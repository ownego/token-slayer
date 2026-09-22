<?php

namespace App\Enums;

use App\Models\Boss;
use App\Support\StoneClock;

/**
 * Boss sprites, in cycle order. Values and order must match BOSS_TYPES in
 * resources/js/battlefield/config/bosses.js: the client picks the sprite as
 * BOSS_TYPES[number % length], and forNumber() mirrors that here so the
 * server can name a boss after the sprite it is actually wearing.
 */
enum BossCharacter: string
{
    case Ghost = 'boss-ghost';
    case Skeleton = 'boss-skeleton';
    case AbyssalDreadknight = 'boss-abyssal-dreadknight';
    case Slime = 'boss-slime';
    case FlyingDemon = 'boss-flying-demon';
    case Minotaur = 'boss-minotaur';
    case DemonSlime = 'boss-demon-slime';
    case Thanos = 'boss-thanos';

    /**
     * Resolve which sprite a boss number wears — the server-side twin of
     * Boss.bossTypeFor() in resources/js/battlefield/boss/index.js.
     *
     * @param  int  $number
     * @return self
     */
    public static function forNumber(int $number): self
    {
        $cases = self::cases();

        return $cases[$number % count($cases)];
    }

    /**
     * The name a recognizable character always spawns with, or null for a
     * generic monster that draws from BossNameGenerator's shared pool.
     *
     * @return string|null
     */
    public function fixedName(): ?string
    {
        return match ($this) {
            self::Thanos => 'ThaNode',
            default => null,
        };
    }

    /**
     * Extra wire state for this character's client-side script
     * (resources/js/battlefield/boss/scripts/), snake_case for the broadcast;
     * an empty array for a generic monster so its payload shape is unchanged.
     *
     * @param  Boss  $boss
     * @return array<string, mixed>
     */
    public function scriptState(Boss $boss): array
    {
        return match ($this) {
            self::Thanos => StoneClock::state($boss->spawned_at, now()),
            default => [],
        };
    }
}
