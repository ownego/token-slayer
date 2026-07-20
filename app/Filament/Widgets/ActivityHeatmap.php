<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\ActivityHeatmapQuery;
use App\Services\Analytics\UsageFilters;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * Blade widget rendering token activity as a 24×7 hour-of-day by weekday grid;
 * cell intensity scales with token volume, surfacing peak-load windows.
 */
class ActivityHeatmap extends Widget
{
    use InteractsWithPageFilters;

    /**
     * The Blade view rendering the grid.
     *
     * @var string
     */
    protected string $view = 'filament.widgets.activity-heatmap';

    /**
     * How many of the page's columns this widget spans. One column, so the
     * dashboard's 2-column grid fits two of these per row; on the single-column
     * Usage Analytics footer it still fills the row.
     *
     * @var int|string|array<string, int|string|null>
     */
    protected int|string|array $columnSpan = 1;

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
     * Provide the grid, the per-weekday row structure, and the max cell value
     * (for opacity scaling) to the view.
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $grid = app(ActivityHeatmapQuery::class)->get(UsageFilters::fromPageFilters($this->filters ?? []));
        $max = collect($grid)->max('tokens') ?: 1;

        // Index by "weekday:hour" for O(1) lookup in the view.
        $cells = collect($grid)->keyBy(fn (array $c): string => $c['weekday'].':'.$c['hour']);

        return [
            'cells' => $cells,
            'max' => $max,
            'weekdays' => [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 0 => 'Sun'],
        ];
    }
}
