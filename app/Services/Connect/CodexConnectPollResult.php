<?php

namespace App\Services\Connect;

use App\Models\Account;
use App\Services\CodexConnectService;

/**
 * Outcome of one {@see CodexConnectService::poll()} tick:
 * still waiting for the human to approve ('pending'), successfully
 * connected ('done', with the resulting `Account`), or the device code
 * expired unused ('expired').
 */
final class CodexConnectPollResult
{
    /**
     * @param  string  $status  one of 'pending', 'done', 'expired'
     * @param  ?Account  $account  the connected account, only set when status is 'done'
     * @return void
     */
    private function __construct(
        public readonly string $status,
        public readonly ?Account $account = null,
    ) {}

    /**
     * Build a pending result.
     *
     * @return self
     */
    public static function pending(): self
    {
        return new self('pending');
    }

    /**
     * Build a done result.
     *
     * @param  Account  $account  the connected account
     * @return self
     */
    public static function done(Account $account): self
    {
        return new self('done', $account);
    }

    /**
     * Build an expired result.
     *
     * @return self
     */
    public static function expired(): self
    {
        return new self('expired');
    }
}
