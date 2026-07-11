<?php

namespace App\Exceptions;

use App\Services\AccountConnectService;
use Exception;
use Throwable;

/**
 * Thrown when the admin-facing PKCE connect flow ({@see AccountConnectService})
 * cannot complete: the cached PKCE state is missing/expired/already used, the
 * authorized profile carries no email, or the newly-authorized Claude
 * account's identity does not match the account being connected.
 *
 * Carries a machine-readable `reason` (e.g. 'connect_state_expired',
 * 'connect_no_identity', 'connect_identity_mismatch') so the Filament action
 * can show a friendly, reason-specific notification. Per token hygiene
 * requirements, callers MUST NOT embed raw token material in the message.
 */
class AccountConnectException extends Exception
{
    /**
     * Machine-readable failure kind (e.g. 'connect_state_expired',
     * 'connect_no_identity', 'connect_identity_mismatch') for callers to
     * branch on without parsing the message.
     *
     * @var string
     */
    public readonly string $reason;

    /**
     * Build the exception with a machine-readable reason alongside the
     * human-readable message.
     *
     * @param  string  $reason  machine-readable failure kind, e.g. 'connect_identity_mismatch'
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
