<?php

namespace App\Services\Profile;

use App\Models\User;
use App\Services\Analytics\QuotaGaugesQuery;

/**
 * The fighter sheet's own quota cards — the fleet-wide quota gauge rows
 * ({@see QuotaGaugesQuery}) narrowed to one player's own accounts, each
 * carrying its tracked members and their today's activity.
 */
class AccountQuotaCards
{
    /**
     * @param  QuotaGaugesQuery  $gauges  the fleet-wide quota gauge query
     * @param  AccountMemberActivity  $members  the per-account member list + activity query
     * @return void
     */
    public function __construct(
        private readonly QuotaGaugesQuery $gauges,
        private readonly AccountMemberActivity $members,
    ) {}

    /**
     * @param  User  $user
     * @return array<int, array>
     */
    public function for(User $user): array
    {
        $accountsById = $user->accounts()->get()->keyBy('id');

        if ($accountsById->isEmpty()) {
            return [];
        }

        return collect($this->gauges->get())
            ->filter(fn (array $row): bool => $accountsById->has($row['account_id']))
            ->map(fn (array $row): array => [
                ...$row,
                'members' => $this->members->for($accountsById->get($row['account_id'])),
                'probe_error' => $accountsById->get($row['account_id'])->probe_error,
            ])
            ->values()
            ->all();
    }
}
