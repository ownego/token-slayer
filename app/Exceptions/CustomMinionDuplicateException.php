<?php

namespace App\Exceptions;

/**
 * Thrown when the minion a player adds is already in their set (the same sheet built twice, or the fighter added twice).
 */
class CustomMinionDuplicateException extends PlayerFacingException
{
    /**
     * The message the player sees.
     *
     * @param  string  $message  the minion is already in the set
     * @return void
     */
    public function __construct(string $message = 'Already in your minions.')
    {
        parent::__construct($message);
    }
}
