<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when a custom fighter's strips cannot be written to or moved on the disk.
 */
class CustomCharacterStorageException extends Exception
{
    /**
     * The message the player sees.
     *
     * @param  string  $message  the disk refused a write (full, unwritable, gone)
     * @return void
     */
    public function __construct(string $message = 'Your fighter could not be saved. Please try again.')
    {
        parent::__construct($message);
    }
}
