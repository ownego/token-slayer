<?php

namespace App\Exceptions;

/**
 * Thrown when a player confirms a draft they do not have: never staged, already replaced, or someone else's.
 */
class CustomCharacterDraftMissingException extends PlayerFacingException
{
    /**
     * The message the player sees.
     *
     * @param  string  $message  there is no such draft for this player
     * @return void
     */
    public function __construct(string $message = 'There is no built fighter waiting for you to use. Build one first.')
    {
        parent::__construct($message);
    }
}
