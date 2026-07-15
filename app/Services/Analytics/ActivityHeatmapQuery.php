<?php

namespace App\Services\Analytics;

use App\Services\Analytics\Concerns\ScopesEventsByFilters;
use Illuminate\Support\Facades\DB;

/**
 * Builds the dense 24×7 hour-of-day by weekday token-activity grid for the
 * analytics page's activity heatmap.
 */
final class ActivityHeatmapQuery
{
    use ScopesEventsByFilters;

    /**
     * Token activity as a dense 24×7 grid keyed by weekday (0 = Sunday, SQL
     * convention) and hour of day, honoring the shared filter. Every cell is
     * present; cells with no events report zero tokens. Bucketed on stored
     * (UTC) time.
     *
     * @param  UsageFilters  $filters  the shared analytics filter
     * @return array<int, array{weekday:int, hour:int, tokens:int}>
     */
    public function get(UsageFilters $filters): array
    {
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';
        $weekdayExpr = $isSqlite ? "cast(strftime('%w', events.created_at) as integer)" : 'extract(dow from events.created_at)::int';
        $hourExpr = $isSqlite ? "cast(strftime('%H', events.created_at) as integer)" : 'extract(hour from events.created_at)::int';

        $sums = $this->scopeEvents($filters)
            ->selectRaw("{$weekdayExpr} as weekday, {$hourExpr} as hour, SUM(events.tokens) as tokens")
            ->groupByRaw("{$weekdayExpr}, {$hourExpr}")
            ->get()
            ->keyBy(fn ($row): string => ((int) $row->weekday).':'.((int) $row->hour));

        $grid = [];
        for ($weekday = 0; $weekday < 7; $weekday++) {
            for ($hour = 0; $hour < 24; $hour++) {
                $grid[] = [
                    'weekday' => $weekday,
                    'hour' => $hour,
                    'tokens' => (int) ($sums["{$weekday}:{$hour}"]->tokens ?? 0),
                ];
            }
        }

        return $grid;
    }
}
