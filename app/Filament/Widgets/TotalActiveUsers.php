<?php

namespace App\Filament\Widgets;

use App\Enums\MembershipStatus;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * A single stat card: distinct users with a Tracked membership on any
 * account, of any provider. The `account_user` pivot keys off the shared
 * envelope `accounts.id`, provider-agnostic by construction, so this needs
 * no provider-conditional branching at all.
 */
class TotalActiveUsers extends StatsOverviewWidget
{
    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $count = User::query()
            ->whereHas('accounts', fn ($query) => $query->wherePivot('status', MembershipStatus::Tracked->value))
            ->count();

        return [
            Stat::make('Total active users', (string) $count),
        ];
    }
}
