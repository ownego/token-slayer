<?php

namespace App\Exceptions;

use App\Services\CodexConnectService;
use Exception;
use Throwable;

/**
 * Thrown when Codex account-connect work fails — a Step A upload with no
 * decodable `chatgpt_account_id`, a Step B upload whose identity doesn't
 * match the target account (the self-graft guard), or (from
 * {@see CodexConnectService}) a device-code flow failure.
 *
 * Carries a machine-readable `reason` (e.g. 'codex_connect_invalid_authjson',
 * 'codex_connect_identity_mismatch', 'codex_connect_device_code_disabled',
 * 'codex_connect_expired') so the Filament action can show a friendly,
 * reason-specific notification, mirroring {@see AccountConnectException}.
 */
class CodexConnectException extends Exception
{
    /**
     * Machine-readable failure kind for callers to branch on without
     * parsing the message.
     *
     * @var string
     */
    public readonly string $reason;

    /**
     * Build the exception with a machine-readable reason alongside the
     * human-readable message.
     *
     * @param  string  $reason  machine-readable failure kind
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
