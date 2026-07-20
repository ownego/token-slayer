<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\AccountContributorsQuery;
use App\Services\Analytics\QuotaGaugesQuery;
use App\Services\Analytics\UsageFilters;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * Blade widget showing a current quota gauge card per account: 5h and 7d
 * utilization bars, time-to-reset, projected utilization at reset, a near-cap
 * flag, and the account's contributors. The quota bars reflect live state; the
 * per-member token figures honor the dashboard's time filter and its
 * "total across accounts" toggle.
 */
class FleetQuotaOverview extends Widget
{
    use InteractsWithPageFilters;

    /**
     * The Blade view rendering the gauge cards.
     *
     * @var string
     */
    protected string $view = 'filament.widgets.fleet-quota-overview';

    /**
     * How many of the page's columns this widget spans.
     *
     * @var int|string|array<string, int|string|null>
     */
    protected int|string|array $columnSpan = 'full';

    /**
     * Only users granted this widget's own View permission see it, so the
     * role editor's Widgets tab toggles each chart independently.
     * super_admin passes via the Gate::before bypass.
     *
     * @return bool
     */
    public static function canView(): bool
    {
        return auth()->user()?->can('View:FleetQuotaOverview') ?? false;
    }

    /**
     * Provide the per-account gauge rows and the contributor breakdown (keyed
     * by account id) to the view. The breakdown is windowed by the dashboard's
     * time filter and switched between per-account and cross-account totals by
     * the `total_across_accounts` toggle.
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $pageFilters = $this->pageFilters ?? [];
        $filters = UsageFilters::fromPageFilters($pageFilters);
        $totalAcrossAccounts = (bool) ($pageFilters['total_across_accounts'] ?? false);

        $contributors = app(AccountContributorsQuery::class);
        $accountTotals = $contributors->accountTotals($filters);

        return [
            'gauges' => app(QuotaGaugesQuery::class)->get(),
            'contributors' => $contributors->get($filters, $totalAcrossAccounts),
            'accountTotals' => $accountTotals,
            'totalUsage' => array_sum($accountTotals),
        ];
    }
}
