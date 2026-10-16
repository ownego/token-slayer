<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when a player asks for a fighter or version they do not have, or one that cannot be touched (the one in use).
 */
class CustomCharacterVersionException extends Exception
{
    /**
     * The message the player sees.
     *
     * @param  string  $message  the fighter or version is missing or in use
     * @return void
     */
    public function __construct(string $message = 'That fighter or version is not available.')
    {
        parent::__construct($message);
    }
}
