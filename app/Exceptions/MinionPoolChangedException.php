<?php

namespace App\Exceptions;

/**
 * Thrown when the pool a player saves (or the minion they remove) is not the one on
 * the server any more: another tab replaced, removed or added a minion since the tab
 * drew its cells, so the hashes it sends no longer match. Nothing is written; the
 * player looks at the pool as it is now and tries again.
 */
class MinionPoolChangedException extends PlayerFacingException
{
    /**
     * The message the player sees.
     *
     * @param  string  $message  the pool changed in another tab
     * @return void
     */
    public function __construct(string $message = 'Your minions changed in another tab. Check the pool, then try again.')
    {
        parent::__construct($message);
    }
}
