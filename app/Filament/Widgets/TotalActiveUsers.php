<?php

namespace App\Filament\Widgets;

use App\Enums\MembershipStatus;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Main-dashboard KPI card: the count of distinct users who are Tracked
 * members of at least one company account — this repo's existing
 * definition of "membership" (see
 * {@see \App\Services\AccountProvisioningService::memberships()}). Bundled
 * into the refresh-token-expiry-visibility delivery for convenience; not
 * conceptually related to that feature.
 */
class TotalActiveUsers extends StatsOverviewWidget
{
    /**
     * Build the single stat card.
     *
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $count = User::query()
            ->whereHas('accounts', fn ($query) => $query->where('account_user.status', MembershipStatus::Tracked->value))
            ->distinct()
            ->count();

        return [
            Stat::make('Total Active Users', (string) $count),
        ];
    }
}
