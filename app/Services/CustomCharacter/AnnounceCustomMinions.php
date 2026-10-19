<?php

namespace App\Services\CustomCharacter;

use App\Events\FighterMinionsChanged;
use App\Models\User;

/**
 * Tells every client on the battlefield that a player's minion set changed, so
 * they load (or drop) the minions without a reload. A broadcast that cannot be
 * sent must not undo the change: it is saved either way and shows up on the next
 * reload.
 */
class AnnounceCustomMinions
{
    /**
     * Broadcasts the player's current minion set.
     *
     * @param  User  $user  the player whose minions changed
     * @return void
     */
    public function execute(User $user): void
    {
        $user->unsetRelation('customMinions');

        rescue(fn () => FighterMinionsChanged::dispatch($user));
    }
}
