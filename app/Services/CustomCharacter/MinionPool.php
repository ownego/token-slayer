<?php

namespace App\Services\CustomCharacter;

use App\Models\User;

/**
 * The looks a player's subagent summons draw from, worked out in one place and sent
 * to every client as `minion_pool`: the default looks still ticked plus the ticked
 * custom minions. Only what the player un-ticked is stored (`users.minion_defaults_off`
 * and `enabled` on a minion), so a player who never touched a tick gets the five
 * defaults and every minion they add. The pool is never empty: with nothing ticked
 * it is the five defaults.
 */
class MinionPool
{
    /**
     * The player's pool, with the revision that orders one pool against another.
     *
     * @param  User  $user  the player
     * @return array{rev: int, defaults: list<string>, custom: list<array{version: string, url_base: string, animations: array<string, array{frames: int, rate: int}>, body_px: int}>}
     */
    public function get(User $user): array
    {
        $allDefaults = array_values(config('token_slayer.custom_character.default_minions'));
        $off = $user->minion_defaults_off ?? [];

        $defaults = array_values(array_diff($allDefaults, $off));
        $custom = $user->customMinions
            ->filter(fn ($minion): bool => $minion->enabled)
            ->map(fn ($minion): array => $minion->broadcastState())
            ->values()
            ->all();

        if ($defaults === [] && $custom === []) {
            $defaults = $allDefaults;
        }

        return [
            'rev' => (int) $user->minion_pool_rev,
            'defaults' => $defaults,
            'custom' => $custom,
        ];
    }
}
