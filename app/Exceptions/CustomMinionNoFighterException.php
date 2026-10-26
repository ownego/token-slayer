<?php

namespace App\Exceptions;

/**
 * Thrown when a player asks to make a minion from their custom fighter but has not made one.
 */
class CustomMinionNoFighterException extends PlayerFacingException
{
    /**
     * The message the player sees.
     *
     * @param  string  $message  there is no custom fighter to copy
     * @return void
     */
    public function __construct(string $message = 'You have no custom fighter yet. Make one in the Fighter tab.')
    {
        parent::__construct($message);
    }
}
