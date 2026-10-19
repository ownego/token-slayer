<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when a player adds a minion preview they no longer have: never built, already replaced, or someone else's.
 */
class CustomMinionDraftMissingException extends Exception
{
    /**
     * The message the player sees.
     *
     * @param  string  $message  there is no such preview for this player
     * @return void
     */
    public function __construct(string $message = 'This preview was replaced. Build it again.')
    {
        parent::__construct($message);
    }
}
