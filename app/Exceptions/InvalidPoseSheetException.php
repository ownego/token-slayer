<?php

namespace App\Exceptions;

use App\Enums\PoseSheetProblem;
use Exception;

/**
 * Thrown when an uploaded custom-character pose sheet cannot be used.
 *
 * Carries the machine-readable {@see PoseSheetProblem} so the upload UI can
 * show a specific reason instead of parsing the message.
 */
class InvalidPoseSheetException extends Exception
{
    /**
     * Build the exception from the problem found.
     *
     * @param  PoseSheetProblem  $problem  machine-readable reason for the rejection
     * @param  string  $detail  optional extra detail appended to the problem's message
     * @return void
     */
    public function __construct(public readonly PoseSheetProblem $problem, string $detail = '')
    {
        parent::__construct(trim($problem->message().' '.$detail));
    }
}
