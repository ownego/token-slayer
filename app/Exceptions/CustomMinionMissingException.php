<?php

namespace App\Exceptions;

/**
 * Thrown when a player asks for a minion they do not have, or one that is already gone.
 */
class CustomMinionMissingException extends PlayerFacingException
{
    /**
     * The message the player sees.
     *
     * @param  string  $message  the minion is missing
     * @return void
     */
    public function __construct(string $message = 'That minion is not available.')
    {
        parent::__construct($message);
    }
}
