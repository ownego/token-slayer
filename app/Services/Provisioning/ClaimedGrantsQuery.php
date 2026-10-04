<?php

namespace App\Services\Provisioning;

use App\Enums\GrantStatus;
use App\Models\Account;
use App\Models\AccountProvisionedGrant;
use Illuminate\Support\Collection;

/**
 * Claimed Claude grants — one per device a member has actually set up —
 * ordered the way an admin works through them: overdue first, then the
 * soonest session deadline, unknown deadlines last.
 */
final class ClaimedGrantsQuery
{
    /**
     * Claimed grants on one account.
     *
     * @param  Account  $account  the account
     * @return Collection<int, AccountProvisionedGrant>
     */
    public function forAccount(Account $account): Collection
    {
        return $this->forAccounts([$account->id]);
    }

    /**
     * Claimed grants on several accounts, device and user eager-loaded.
     *
     * @param  array<int, int>  $accountIds  the account ids
     * @return Collection<int, AccountProvisionedGrant>
     */
    public function forAccounts(array $accountIds): Collection
    {
        return AccountProvisionedGrant::query()
            ->whereIn('account_id', $accountIds)
            ->where('status', GrantStatus::Claimed->value)
            ->with('device.user')
            ->orderByRaw('session_expires_at IS NULL')
            ->orderBy('session_expires_at')
            ->get();
    }
}
