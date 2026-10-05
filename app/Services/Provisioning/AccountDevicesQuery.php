<?php

namespace App\Services\Provisioning;

use App\Enums\GrantStatus;
use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\AccountProvisionedGrant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Live Claude grants — one per device issued to a tracked or pending member,
 * set up or not — ordered the way an admin works through them: overdue
 * first, then the soonest session deadline, unknown deadlines next and
 * devices not yet set up last.
 */
final class AccountDevicesQuery
{
    /**
     * Live grants on one account held by its tracked or pending members,
     * device and user eager-loaded.
     *
     * @param  Account  $account  the account
     * @return Collection<int, AccountProvisionedGrant>
     */
    public function forAccount(Account $account): Collection
    {
        $memberIds = $account->users()
            ->wherePivotIn('status', [MembershipStatus::Tracked->value, MembershipStatus::Pending->value])
            ->pluck('users.id');

        return AccountProvisionedGrant::query()
            ->live()
            ->where('account_id', $account->id)
            ->whereHas('device', fn (Builder $query): Builder => $query->whereIn('user_id', $memberIds))
            ->with('device.user')
            ->orderByRaw('status = ?', [GrantStatus::Pending->value])
            ->orderByRaw('session_expires_at IS NULL')
            ->orderBy('session_expires_at')
            ->orderBy('id')
            ->get();
    }
}
