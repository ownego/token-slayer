<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\TopUsersQuery;
use App\Services\Analytics\UsageFilters;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Horizontal bar chart of the top users by token spend in the filtered range.
 */
class TopUsersLeaderboard extends ChartWidget
{
    use InteractsWithPageFilters;

    /**
     * The heading shown above the chart.
     *
     * @var string|null
     */
    protected ?string $heading = 'Top users';

    /**
     * How many of the page's columns this widget spans. One column, so the
     * dashboard's 2-column grid fits two of these per row; on the single-column
     * Usage Analytics footer it still fills the row.
     *
     * @var int|string|array<string, int|string|null>
     */
    protected int|string|array $columnSpan = 1;

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
     * Only users granted the usage-analytics permission see this widget.
     * super_admin passes via the Gate::before bypass.
     *
     * @return bool
     */
    public static function canView(): bool
    {
        return auth()->user()?->can('view_usage_analytics') ?? false;
    }

    /**
     * Build the Chart.js dataset from the top-users leaderboard.
     *
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $rows = app(TopUsersQuery::class)->get(UsageFilters::fromPageFilters($this->filters ?? []), self::LIMIT);

        return [
            'datasets' => [[
                'label' => 'Tokens',
                'data' => collect($rows)->pluck('tokens')->all(),
                'backgroundColor' => '#d97706',
            ]],
            'labels' => collect($rows)->pluck('handle')->all(),
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
