<?php

namespace App\Services\Accounts;

use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Services\Analytics\AccountContributorsQuery;

/**
 * Every member of an account for the rebalance page's own member list,
 * always including `Pending` (a grant has been issued but not yet claimed)
 * unlike {@see AccountContributorsQuery} (which only
 * seeds `Tracked` rows, by design, for the Fleet Quota widget), and
 * optionally including `Untracked` — hidden by default, mirroring
 * `MembersRelationManager`'s existing "Unverified members" toggle.
 */
final class AccountMemberStatusQuery
{
    /**
     * @param  Account  $account  the account to list members for
     * @param  bool  $includeUntracked  whether to also include Untracked members (default: hidden)
     * @return array<int, array{user_id: int, handle: string, status: string}>
     */
    public function get(Account $account, bool $includeUntracked = false): array
    {
        $statuses = [
            MembershipStatus::Tracked->value,
            MembershipStatus::Pending->value,
        ];
        if ($includeUntracked) {
            $statuses[] = MembershipStatus::Untracked->value;
        }

        return $account->users()
            ->wherePivotIn('status', $statuses)
            ->get()
            ->map(fn ($user): array => [
                'user_id' => $user->id,
                'handle' => $user->displayHandle(),
                'status' => $user->pivot->status->value,
            ])
            ->all();
    }
}
