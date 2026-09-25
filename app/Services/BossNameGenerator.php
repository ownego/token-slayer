<?php

namespace App\Services;

use App\Enums\BossCharacter;
use App\Models\Boss;

class BossNameGenerator
{
    /** @var array<int, string> */
    private const POOL = [
        'Smaug', 'Vermithrax', 'Glaurung', 'Tiamat', 'Bahamut',
        'Drogon', 'Ancalagon', 'Falkor', 'Niðhöggr', 'Fafnir',
        'Cthulhu', 'Dagon', 'Azathoth', 'Nyarlathotep', 'Shub-Niggurath',
        'Kraken', 'Leviathan', 'Behemoth', 'Wendigo', 'Jörmungandr',
        'Onyxia', 'Deathwing', 'Nefarian', 'Ragnaros', 'Illidan',
        'Diablo', 'Mephisto', 'Baal', 'Belial', 'Andariel',
        'Asmodeus', 'Lilith', 'Malphas', 'Belphegor', 'Abaddon',
        'Sephiroth', 'Jenova', 'Bowser', 'Ganon', 'Dracula',
        'Medusa', 'Hydra', 'Cerberus', 'Chimera', 'Manticore',
        'Balrog', 'Shelob', 'Morgoth', 'Sauron', 'Saruman',
    ];

    /**
     * Pick a boss name, avoiding any in `$recent` if possible.
     *
     * Falls back to a random name from the full pool if every entry has been
     * used recently (only happens once the recency window exceeds the pool).
     *
     * @param  array<int, string>  $recent
     */
    public function next(array $recent = []): string
    {
        $available = array_values(array_diff(self::POOL, $recent));
        $candidates = $available !== [] ? $available : self::POOL;

        return $candidates[array_rand($candidates)];
    }

    /**
     * Convenience: pick a name avoiding the last N persisted boss names.
     */
    public function nextForSpawn(int $windowSize = 10): string
    {
        $recent = Boss::query()
            ->orderByDesc('number')
            ->limit($windowSize)
            ->pluck('name')
            ->filter()
            ->all();

        return $this->next($recent);
    }

    /**
     * Name for the boss about to spawn: a recognizable character whose turn
     * has come (BossCharacter::isDueAfter) takes its fixed name, otherwise a
     * pool name avoiding the last few bosses. The name is the boss's
     * identity from then on — see BossCharacter::of().
     *
     * @param  int  $windowSize  How many recent bosses the pool pick avoids.
     * @return string
     */
    public function nameForSpawn(int $windowSize = 10): string
    {
        $recent = Boss::query()
            ->orderByDesc('number')
            ->limit(max($windowSize, ...array_map(fn (BossCharacter $c) => $c->spawnGap(), BossCharacter::cases())))
            ->pluck('name')
            ->all();

        foreach (BossCharacter::cases() as $character) {
            if ($character->isDueAfter($recent)) {
                return $character->fixedName();
            }
        }

        return $this->next(array_values(array_filter(array_slice($recent, 0, $windowSize))));
    }
}
