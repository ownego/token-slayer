<?php

namespace App\Services;

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
}
