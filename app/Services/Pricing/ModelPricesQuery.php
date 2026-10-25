<?php

namespace App\Services\Pricing;

use App\Models\MissingModel;
use App\Models\ModelPrice;

/**
 * Data for the read-only model prices page.
 */
final class ModelPricesQuery
{
    /**
     * Each model's latest price, sorted by model id.
     *
     * @return array<int, array{model:string, provider:string, effective_from:string, input:float, output:float, cache_read:float, cache_write_5m:float, cache_write_1h:?float}>
     */
    public function current(): array
    {
        return ModelPrice::query()
            ->orderBy('model')
            ->orderByDesc('effective_from')
            ->get()
            ->unique('model')
            ->map(fn (ModelPrice $price): array => [
                'model' => $price->model,
                'provider' => $price->provider,
                'effective_from' => $price->effective_from->toDateString(),
                'input' => $price->input_per_mtok,
                'output' => $price->output_per_mtok,
                'cache_read' => $price->cache_read_per_mtok,
                'cache_write_5m' => $price->cache_write_5m_per_mtok,
                'cache_write_1h' => $price->cache_write_1h_per_mtok,
            ])
            ->values()
            ->all();
    }

    /**
     * Models seen on complete events without a price, most recently seen first.
     *
     * @return array<int, array{model:string, first_seen_at:string, last_seen_at:string}>
     */
    public function missing(): array
    {
        return MissingModel::query()
            ->orderByDesc('last_seen_at')
            ->get()
            ->map(fn (MissingModel $missing): array => [
                'model' => $missing->model,
                'first_seen_at' => $missing->first_seen_at->toDateTimeString(),
                'last_seen_at' => $missing->last_seen_at->toDateTimeString(),
            ])
            ->all();
    }
}
