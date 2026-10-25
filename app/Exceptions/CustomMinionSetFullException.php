<?php

namespace App\Exceptions;

/**
 * Thrown when a player adds a minion while their set already holds the most it can; they replace one instead.
 */
class CustomMinionSetFullException extends PlayerFacingException
{
    /**
     * The message the player sees.
     *
     * @param  string  $message  the set is full
     * @return void
     */
    public function __construct(string $message = 'Your minion set is full. Replace one to change it.')
    {
        parent::__construct($message);
    }
}
