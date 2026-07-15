<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\TopAccountsQuery;
use App\Services\Analytics\UsageFilters;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Horizontal bar chart of the top org accounts by token spend in the
 * filtered range.
 */
class TopAccountsLeaderboard extends ChartWidget
{
    use InteractsWithPageFilters;

    /**
     * The heading shown above the chart.
     *
     * @var string|null
     */
    protected ?string $heading = 'Top accounts';

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
    protected ?string $maxHeight = '260px';

    /**
     * Maximum number of leaderboard rows to fetch.
     *
     * @var int
     */
    private const int LIMIT = 10;

    /**
     * Build the Chart.js dataset from the top-accounts leaderboard.
     *
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $rows = app(TopAccountsQuery::class)->get(UsageFilters::fromPageFilters($this->filters ?? []), self::LIMIT);

        return [
            'datasets' => [[
                'label' => 'Tokens',
                'data' => collect($rows)->pluck('tokens')->all(),
                'backgroundColor' => '#2563eb',
            ]],
            'labels' => collect($rows)->pluck('email')->all(),
        ];
    }

    /**
     * The Chart.js chart type.
     *
     * @return string
     */
    protected function getType(): string
    {
        return 'bar';
    }
}
