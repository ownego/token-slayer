<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\TokenVolumeQuery;
use App\Services\Analytics\UsageFilters;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Stacked-area chart of token volume over time, one dataset per provider,
 * driven by the shared analytics page filter.
 */
class TokenVolumeChart extends ChartWidget
{
    use InteractsWithPageFilters;

    /**
     * The heading shown above the chart.
     *
     * @var string|null
     */
    protected ?string $heading = 'Token volume over time';

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
     * Provider slug to line/fill color for the chart datasets.
     *
     * @var array<string, string>
     */
    private const array PROVIDER_COLORS = [
        'claude-code' => '#d97706',
        'codex' => '#2563eb',
        'claude.ai' => '#059669',
    ];

    /**
     * Build the Chart.js datasets/labels from bucketed token volume, one
     * dataset per provider keyed against the shared sorted bucket labels.
     *
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $rows = app(TokenVolumeQuery::class)->get(UsageFilters::fromPageFilters($this->filters ?? []));

        $buckets = collect($rows)->pluck('bucket')->unique()->sort()->values();
        $byProvider = collect($rows)->groupBy('provider');

        $datasets = $byProvider->map(function ($providerRows, string $provider) use ($buckets): array {
            $keyed = collect($providerRows)->keyBy('bucket');

            return [
                'label' => $provider,
                'data' => $buckets->map(fn (string $bucket): int => (int) ($keyed[$bucket]['tokens'] ?? 0))->all(),
                'borderColor' => self::PROVIDER_COLORS[$provider] ?? '#6b7280',
                'backgroundColor' => (self::PROVIDER_COLORS[$provider] ?? '#6b7280').'33',
                'fill' => true,
            ];
        })->values()->all();

        return ['datasets' => $datasets, 'labels' => $buckets->all()];
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
