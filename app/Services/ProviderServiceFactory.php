<?php

namespace App\Services;

use App\Enums\Provider;
use App\Models\Account;
use App\Services\Contracts\AccountDisconnecterContract;
use App\Services\Contracts\GrantRevokerContract;
use App\Services\Contracts\UsageProberContract;

/**
 * Resolves the right provider-specific service implementation for an
 * account, so callers (Filament actions, scheduled commands, analytics
 * refreshers) never branch on `Account::provider` themselves — they ask
 * this factory instead.
 */
final class ProviderServiceFactory
{
    /**
     * @param  UsageProber  $claudeProber  the Claude usage prober
     * @param  CodexUsageProber  $codexProber  the Codex usage prober
     * @param  AccountConnectService  $claudeConnect  the Claude connect/disconnect service
     * @param  CodexConnectService  $codexConnect  the Codex connect/disconnect service
     * @param  AccountProvisioningService  $claudeProvisioning  the Claude per-device grant service
     * @param  CodexProvisioningService  $codexProvisioning  the Codex per-device grant service
     * @return void
     */
    public function __construct(
        private readonly UsageProber $claudeProber,
        private readonly CodexUsageProber $codexProber,
        private readonly AccountConnectService $claudeConnect,
        private readonly CodexConnectService $codexConnect,
        private readonly AccountProvisioningService $claudeProvisioning,
        private readonly CodexProvisioningService $codexProvisioning,
    ) {}

    /**
     * The usage prober for `$account`'s provider.
     *
     * @param  Account  $account  the account whose provider selects the prober
     * @return UsageProberContract
     */
    public function proberFor(Account $account): UsageProberContract
    {
        return $account->provider === Provider::Codex ? $this->codexProber : $this->claudeProber;
    }

    /**
     * The account-disconnect service for `$account`'s provider.
     *
     * @param  Account  $account  the account whose provider selects the disconnecter
     * @return AccountDisconnecterContract
     */
    public function disconnecterFor(Account $account): AccountDisconnecterContract
    {
        return $account->provider === Provider::Codex ? $this->codexConnect : $this->claudeConnect;
    }

    /**
     * The per-device grant revoker for `$grant`'s account's provider.
     *
     * @param  Account  $account  the grant's owning account, whose provider selects the revoker
     * @return GrantRevokerContract
     */
    public function revokerFor(Account $account): GrantRevokerContract
    {
        return $account->provider === Provider::Codex ? $this->codexProvisioning : $this->claudeProvisioning;
    }
}
