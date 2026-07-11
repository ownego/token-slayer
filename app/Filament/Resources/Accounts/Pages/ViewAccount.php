<?php

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Widgets\AccountQuotaGauge;
use App\Filament\Widgets\AccountQuotaHistoryChart;
use Filament\Resources\Pages\ViewRecord;

/**
 * Read-only detail page for one org account: shows the account's quota
 * utilization history (sawtooth chart) and its current gauge + reset
 * projection. Admin-gated panel-wide.
 */
class ViewAccount extends ViewRecord
{
    /**
     * The resource this page belongs to.
     *
     * @var class-string<AccountResource>
     */
    protected static string $resource = AccountResource::class;

    /**
     * Header widgets rendered above the record view. Filament injects the
     * current record into each via `InteractsWithRecord::getWidgetData()`.
     *
     * @return array<int, class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            AccountQuotaHistoryChart::class,
            AccountQuotaGauge::class,
        ];
    }
}
