<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when a built idle strip has no ink in its first frame, so there is no body to measure (an empty pose, or a sheet that keyed away to nothing).
 */
class BlankStripException extends Exception
{
    /**
     * The message the player sees.
     *
     * @param  string  $message  the idle pose is empty
     * @return void
     */
    public function __construct(string $message = 'The idle pose is empty. Check the sheet and build again.')
    {
        parent::__construct($message);
    }
}
