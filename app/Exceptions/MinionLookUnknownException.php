<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when a player ticks or unticks a minion look that does not exist or is not theirs:
 * a default look that is not in the list, or a slot they hold no minion in.
 */
class MinionLookUnknownException extends Exception
{
    /**
     * The message the player sees.
     *
     * @param  string  $message  the look is not in the player's pool
     * @return void
     */
    public function __construct(string $message = 'That minion is not available.')
    {
        parent::__construct($message);
    }
}
