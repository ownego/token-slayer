<?php

namespace App\Services\CustomCharacter;

use App\Events\FighterMinionsChanged;
use App\Models\User;

/**
 * Tells every client on the battlefield that a player's minion pool changed, so
 * they load (or drop) the minions without a reload. A broadcast that cannot be
 * sent must not undo the change: it is saved either way and shows up on the next
 * reload.
 */
class AnnounceCustomMinions
{
    /**
     * Broadcasts the player's current minion pool. The player is read again first:
     * a model loaded before the caller took the player's lock holds an older pool
     * revision, and a client drops a pool whose revision is not newer than the one it has.
     *
     * @param  User  $user  the player whose minions changed
     * @return void
     */
    public function execute(User $user): void
    {
        rescue(function () use ($user): void {
            $user->unsetRelation('customMinions');
            $user->refresh();

            FighterMinionsChanged::dispatch($user);
        });
    }
}
