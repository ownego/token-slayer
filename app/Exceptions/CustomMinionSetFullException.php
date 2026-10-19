<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when a player adds a minion while their set already holds the most it can.
 */
class CustomMinionSetFullException extends Exception
{
    /**
     * The message the player sees.
     *
     * @param  string  $message  the set is full
     * @return void
     */
    public function __construct(string $message = '3 of 3. Remove one to add another.')
    {
        parent::__construct($message);
    }
}
