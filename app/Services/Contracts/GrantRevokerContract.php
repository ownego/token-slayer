<?php

namespace App\Services\Contracts;

use App\Models\AccountProvisionedGrant;
use App\Services\AccountProvisioningService;
use App\Services\CodexProvisioningService;
use App\Services\ProviderServiceFactory;

/**
 * Shared contract for a provider's per-device grant revoke
 * ({@see AccountProvisioningService} for Claude,
 * {@see CodexProvisioningService} for Codex) — lets callers
 * resolve the right revoker via
 * {@see ProviderServiceFactory} instead of branching on
 * `$grant->account->provider` themselves.
 */
interface GrantRevokerContract
{
    /**
     * Soft-revoke a per-device grant.
     *
     * @param  AccountProvisionedGrant  $grant  the grant to revoke
     * @return void
     */
    public function revoke(AccountProvisionedGrant $grant): void;
}
