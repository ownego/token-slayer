<?php

namespace App\Services\Contracts;

use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use App\Services\CodexUsageProber;
use App\Services\ProviderServiceFactory;
use App\Services\UsageProber;

/**
 * Shared contract for a provider's usage prober ({@see UsageProber}
 * for Claude, {@see CodexUsageProber} for Codex) — lets callers
 * resolve the right prober via {@see ProviderServiceFactory}
 * instead of branching on `Account::provider` themselves.
 */
interface UsageProberContract
{
    /**
     * Probe a single account's current usage and record a snapshot.
     *
     * @param  Account  $account  the org account to probe
     * @return AccountUsageSnapshot|null the recorded snapshot, or null when the account was skipped or the probe failed
     */
    public function probe(Account $account): ?AccountUsageSnapshot;
}
