<?php

namespace App\Services\Analytics;

use App\Services\Analytics\Concerns\ScopesEventsByFilters;

/**
 * Reads token volume grouped by time bucket and provider for the analytics
 * page's token-volume chart.
 */
final class TokenVolumeQuery
{
    use ScopesEventsByFilters;

    /**
     * Token volume grouped by time bucket and provider within the filter's
     * range, honoring the optional account/provider/user narrowing. The
     * bucket is a sortable string (`Y-m-d H:00` hourly or `Y-m-d` daily).
     *
     * @param  UsageFilters  $filters  the shared analytics filter
     * @return array<int, array{bucket:string, provider:string, tokens:int}>
     */
    public function get(UsageFilters $filters): array
    {
        $bucketExpr = $this->bucketExpression($filters->bucket, 'events.created_at');

        return $this->scopeEvents($filters)
            ->selectRaw("{$bucketExpr} as bucket")
            ->selectRaw('events.provider as provider')
            ->selectRaw('SUM(events.tokens) as tokens')
            ->groupByRaw("{$bucketExpr}, events.provider")
            ->orderByRaw("{$bucketExpr}")
            ->get()
            ->map(fn ($row): array => [
                'bucket' => (string) $row->bucket,
                'provider' => (string) $row->provider,
                'tokens' => (int) $row->tokens,
            ])
            ->all();
    }
}
