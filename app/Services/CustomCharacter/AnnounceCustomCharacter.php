<?php

namespace App\Services\CustomCharacter;

use App\Events\FighterCharacterChanged;
use App\Models\User;

/**
 * Tells every client on the battlefield that a player's fighter changed, so they
 * load (or drop) the custom strips without a reload. A broadcast that cannot be
 * sent must not undo the change: it is saved either way and shows up on the next
 * reload.
 */
class AnnounceCustomCharacter
{
    /**
     * Broadcasts the player's current fighter state.
     *
     * @param  User  $user  the player whose fighter changed
     * @return void
     */
    public function execute(User $user): void
    {
        $user->unsetRelation('customCharacter');

        rescue(fn () => FighterCharacterChanged::dispatch($user));
    }
}
