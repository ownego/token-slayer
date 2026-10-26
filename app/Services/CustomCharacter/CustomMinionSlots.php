<?php

namespace App\Services\CustomCharacter;

use App\Models\User;

/**
 * Which of a player's minion slots are taken. The one place that knows the cap
 * (`custom_character.minions_max`), so a service and a view can never disagree
 * about whether the set is full.
 */
class CustomMinionSlots
{
    /**
     * The lowest slot, from 1 up to the cap, that holds no minion yet.
     *
     * @param  User  $user  the player
     * @return int|null the free slot, or null when the set is full
     */
    public function firstFree(User $user): ?int
    {
        $taken = $user->customMinions->pluck('slot')->all();

        for ($slot = 1; $slot <= $this->max(); $slot++) {
            if (! in_array($slot, $taken, true)) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * Whether the player holds as many minions as the cap lets them.
     *
     * @param  User  $user  the player
     * @return bool
     */
    public function isFull(User $user): bool
    {
        return $user->customMinions->count() >= $this->max();
    }

    /**
     * How many custom minions one player may hold at once.
     *
     * @return int
     */
    private function max(): int
    {
        return (int) config('token_slayer.custom_character.minions_max');
    }
}
