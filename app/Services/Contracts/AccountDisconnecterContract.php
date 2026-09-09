<?php

namespace App\Services\Contracts;

use App\Models\Account;
use App\Services\AccountConnectService;
use App\Services\CodexConnectService;
use App\Services\ProviderServiceFactory;

/**
 * Shared contract for a provider's account-level disconnect
 * ({@see AccountConnectService} for Claude,
 * {@see CodexConnectService} for Codex) — lets callers
 * resolve the right disconnecter via
 * {@see ProviderServiceFactory} instead of branching on
 * `Account::provider` themselves.
 */
interface AccountDisconnecterContract
{
    /**
     * Sever the stored OAuth grant for an account.
     *
     * @param  Account  $account  the account to disconnect
     * @return void
     */
    public function disconnect(Account $account): void;
}
