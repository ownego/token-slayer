<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Models\Account;
use App\Services\Analytics\AccountQuotaHistoryQuery;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Model;

/**
 * Line chart of one account's 5h and 7d quota utilization over the last 7
 * days — the sawtooth burn/reset pattern. Record-aware: rendered on the
 * account's {@see ViewAccount} page.
 */
class AccountQuotaHistoryChart extends ChartWidget
{
    /**
     * The account record this widget belongs to, injected by the view page.
     *
     * @var Model|null
     */
    public ?Model $record = null;

    /**
     * The heading shown above the chart.
     *
     * @var string|null
     */
    protected ?string $heading = 'Quota utilization (7 days)';

    /**
     * How many of the page's columns this widget spans (full-width row).
     *
     * @var int|string|array<string, int|string|null>
     */
    protected int|string|array $columnSpan = 'full';

    /**
     * Maximum canvas height so the full-width chart stays compact.
     *
     * @var string|null
     */
    protected ?string $maxHeight = '240px';

    /**
     * Build the Chart.js datasets from the account's snapshot history over
     * the last 7 days.
     *
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        if ($this->record === null) {
            return ['datasets' => [], 'labels' => []];
        }

        /** @var Account $account */
        $account = $this->record;

        $rows = app(AccountQuotaHistoryQuery::class)->get($account, now()->subDays(7), now());

        return [
            'datasets' => [
                [
                    'label' => '5h %',
                    'data' => collect($rows)->pluck('util_5h')->all(),
                    'borderColor' => '#2563eb',
                ],
                [
                    'label' => '7d %',
                    'data' => collect($rows)->pluck('util_7d')->all(),
                    'borderColor' => '#dc2626',
                ],
            ],
            'labels' => collect($rows)->pluck('bucket')->all(),
        ];
    }

    /**
     * The Chart.js chart type.
     *
     * @return string
     */
    protected function getType(): string
    {
        return 'line';
    }
}
