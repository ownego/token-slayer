<?php

namespace App\Enums;

use App\Models\Boss;
use App\Support\StoneClock;

/**
 * Recognizable bosses: characters with their own name, sprite and script,
 * as opposed to generic monsters that rotate through the sprite roster by
 * boss number and draw a name from BossNameGenerator's pool.
 *
 * A boss's identity is the name it was spawned with, never its number, so
 * adding a character does not re-skin bosses already alive. Each value is
 * the BOSS_TYPES key in resources/js/battlefield/config/bosses.js whose
 * entry carries the same fixedName; the client resolves the sprite the
 * same way (bossTypeOf in resources/js/battlefield/boss/boss-type.js).
 */
enum BossCharacter: string
{
    case Thanos = 'boss-thanos';

    /**
     * The recognizable character a boss is, judged by its stored name, or
     * null for a generic monster.
     *
     * @param  Boss  $boss
     * @return self|null
     */
    public static function of(Boss $boss): ?self
    {
        foreach (self::cases() as $character) {
            if ($boss->name === $character->fixedName()) {
                return $character;
            }
        }

        return null;
    }

    /**
     * The name this character always spawns with.
     *
     * @return string
     */
    public function fixedName(): string
    {
        return match ($this) {
            self::Thanos => 'ThaNode',
        };
    }

    /**
     * How many bosses in a row must go by without this character before it
     * spawns again: seven keeps it at one boss in eight.
     *
     * @return int
     */
    public function spawnGap(): int
    {
        return match ($this) {
            self::Thanos => 7,
        };
    }

    /**
     * Whether the next boss should be this character, given the names of
     * the most recent bosses, newest first. Needs a full gap of history, so
     * a fresh arena opens with generic monsters.
     *
     * @param  array<int, string|null>  $recentNamesNewestFirst
     * @return bool
     */
    public function isDueAfter(array $recentNamesNewestFirst): bool
    {
        $window = array_slice($recentNamesNewestFirst, 0, $this->spawnGap());

        return count($window) === $this->spawnGap()
            && ! in_array($this->fixedName(), $window, true);
    }

    /**
     * Extra wire state for this character's client-side script
     * (resources/js/battlefield/boss/scripts/), snake_case for the broadcast.
     *
     * @param  Boss  $boss
     * @return array<string, mixed>
     */
    public function scriptState(Boss $boss): array
    {
        return match ($this) {
            self::Thanos => StoneClock::state($boss->spawned_at, now()),
        };
    }

    /**
     * A short clause for the "new boss incoming" Slack line describing what
     * this character spawns with — e.g. "holding the Power Stone".
     *
     * @param  Boss  $boss
     * @return string|null
     */
    public function spawnFlavor(Boss $boss): ?string
    {
        return match ($this) {
            self::Thanos => $this->stoneFlavor((int) ($this->scriptState($boss)['stones'] ?? 0)),
        };
    }

    /**
     * "holding the Power Stone" / "holding 3 Infinity Stones", or null when
     * the boss holds none.
     *
     * @param  int  $stones
     * @return string|null
     */
    private function stoneFlavor(int $stones): ?string
    {
        return match (true) {
            $stones === 1 => sprintf('holding the %s', StoneClock::nameOf(1)),
            $stones > 1 => sprintf('holding %d Infinity Stones', $stones),
            default => null,
        };
    }
}
