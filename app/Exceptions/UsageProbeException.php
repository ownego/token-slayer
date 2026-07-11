<?php

namespace App\Exceptions;

use Exception;
use Throwable;

/**
 * Thrown when a call to Anthropic's OAuth token/usage/profile API fails.
 *
 * Carries a machine-readable `reason` (e.g. 'invalid_grant', 'rate_limited',
 * 'http_error') so callers — the prober, the connect flow, notifications —
 * can branch on failure kind without parsing the message string. Per token
 * hygiene requirements, callers MUST NOT embed raw token material in the
 * message.
 */
class UsageProbeException extends Exception
{
    /**
     * Machine-readable failure kind (e.g. 'invalid_grant', 'rate_limited',
     * 'http_error') for callers to branch on without parsing the message.
     *
     * @var string
     */
    public readonly string $reason;

    /**
     * Build the exception with a machine-readable reason alongside the
     * human-readable message.
     *
     * @param  string  $reason  machine-readable failure kind, e.g. 'invalid_grant'
     * @param  string  $message  human-readable detail; must never contain raw token material
     * @param  ?Throwable  $previous  the underlying exception, if any
     * @return void
     */
    public function __construct(string $reason, string $message = '', ?Throwable $previous = null)
    {
        $this->reason = $reason;

        parent::__construct($message !== '' ? $message : $reason, 0, $previous);
    }
}
