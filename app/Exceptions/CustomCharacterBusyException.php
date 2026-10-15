<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when the player already has a build running and this one gave up waiting.
 */
class CustomCharacterBusyException extends Exception
{
    /**
     * The message the player sees.
     *
     * @param  string  $message  another build by the same player holds the lock
     * @return void
     */
    public function __construct(string $message = 'Another build of yours is still running. Try again in a moment.')
    {
        parent::__construct($message);
    }
}
