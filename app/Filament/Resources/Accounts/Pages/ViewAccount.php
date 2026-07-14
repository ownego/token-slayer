<?php

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Widgets\AccountQuotaGauge;
use App\Filament\Widgets\AccountQuotaHistoryChart;
use Filament\Actions\EditAction;
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
     * Header actions rendered above the record view: an Edit button, since
     * this page otherwise has no way to reach the edit form from the detail
     * view.
     *
     * @return array<int, EditAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }

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

    /**
     * Render the header widgets in a single column so each occupies a
     * full-width row.
     *
     * @return int|array<string, int|null>
     */
    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
