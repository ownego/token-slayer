<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\QuotaGaugesQuery;
use Filament\Widgets\Widget;

/**
 * Blade widget showing a current quota gauge card per account: 5h and 7d
 * utilization bars, time-to-reset, projected utilization at reset, and a
 * near-cap flag. Reflects live state, so it ignores the page's time filter.
 */
class FleetQuotaOverview extends Widget
{
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
     * Provide the per-account gauge rows to the view.
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['gauges' => app(QuotaGaugesQuery::class)->get()];
    }
}
