<?php

namespace App\Services\Analytics;

use App\Models\Account;
use App\Services\DamageTotals;
use App\Services\ProviderServiceFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Re-probes every probeable org account's current usage on demand (the
 * Fleet-quota widget's Refresh button) and busts the cached damage totals so
 * the analytics widgets recompute from the fresh snapshots. Each probe is
 * isolated: one account's failure never aborts the sweep. Fans out over both
 * providers via {@see ProviderServiceFactory::proberFor()}.
 */
final class FleetUsageRefresher
{
    /**
     * @param  ProviderServiceFactory  $probers  resolves each account's provider-specific prober
     * @return void
     */
    public function __construct(private readonly ProviderServiceFactory $probers) {}

    /**
     * Probe every probeable account of either provider, forget the
     * damage-totals cache, and return how many accounts were attempted.
     *
     * @return int the number of accounts probed
     */
    public function refresh(): int
    {
        return $this->refreshAccounts(Account::probeable()->get()->merge(Account::codexProbeable()->get()));
    }

    /**
     * Probe exactly the given accounts (never a wider set) and forget the
     * damage-totals cache. Each probe runs inside a per-account
     * `Cache::lock`: Anthropic rotates the refresh token on every use and
     * `AccountTokenRefresher` has no lock of its own, so two concurrent
     * probes of one account (the scheduled sweep and a player's own
     * fighter-sheet refresh landing at the same moment) would make the
     * loser `invalid_grant`, flip the account to NeedsReauth, and fire a
     * false `AccountTokenRejected` alert. An account whose lock is already
     * held is skipped for this call, not queued or retried.
     *
     * @param  Collection<int, Account>  $accounts  exactly the accounts to probe
     * @return int the number of accounts actually probed (excludes any skipped for a held lock)
     */
    public function refreshAccounts(Collection $accounts): int
    {
        $probed = 0;

        foreach ($accounts as $account) {
            $lock = Cache::lock("account-probe:{$account->id}", 60);
            if (! $lock->get()) {
                continue;
            }

            try {
                $this->probers->proberFor($account)->probe($account);
                $probed++;
            } catch (\Throwable) {
                // A single account's failure must not abort the fleet sweep;
                // the prober already records a safe probe_error per account.
                $probed++;
            } finally {
                $lock->release();
            }
        }

        Cache::forget(DamageTotals::CACHE_KEY);

        return $probed;
    }
}
