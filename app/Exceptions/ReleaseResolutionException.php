<?php

namespace App\Exceptions;

use Exception;
use Throwable;

/**
 * Thrown when the server cannot resolve or download a slayer-cli release
 * artifact from GitHub.
 *
 * Never thrown to a caller — the services report() it and return null so the
 * profile page and install-script render stay fail-soft. It exists to give the
 * exception log a named, greppable failure instead of a silent null. Per token
 * hygiene requirements the message MUST NOT contain the PAT.
 */
class ReleaseResolutionException extends Exception
{
    /**
     * Machine-readable failure kind ('http_error', 'no_release', 'no_asset',
     * 'unconfigured', 'transport') for log triage without parsing the message.
     *
     * @var string
     */
    public readonly string $reason;

    /**
     * Build the exception with a machine-readable reason alongside the
     * human-readable detail.
     *
     * @param  string  $reason  machine-readable failure kind, e.g. 'http_error'
     * @param  string  $message  human-readable detail; must never contain the PAT
     * @param  ?Throwable  $previous  the underlying exception, if any
     * @return void
     */
    public function __construct(string $reason, string $message = '', ?Throwable $previous = null)
    {
        $this->reason = $reason;

        parent::__construct($message !== '' ? $message : $reason, 0, $previous);
    }
}
