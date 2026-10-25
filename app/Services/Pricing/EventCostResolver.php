<?php

namespace App\Services\Pricing;

/**
 * Prices a turn as it is ingested.
 */
final class EventCostResolver
{
    /**
     * Build the resolver from the cached lookup and the pure formula.
     *
     * @param  ModelPriceLookup  $prices  the cached current-price lookup
     * @param  EventCostCalculator  $calculator  the cost formula
     */
    public function __construct(
        private ModelPriceLookup $prices,
        private EventCostCalculator $calculator,
    ) {}

    /**
     * The turn's cost, or why it has none: an output-only provider or an
     * incomplete turn gets nothing, a complete turn with no price names its
     * model for the price sync.
     *
     * @param  PricedUsage  $usage  the turn's token counts
     * @return EventCost
     */
    public function resolve(PricedUsage $usage): EventCost
    {
        if (! $usage->isPricedProvider() || ! $usage->hasFullData()) {
            return new EventCost(null, null);
        }

        $model = (string) $usage->model;
        $price = $this->prices->current($model);

        if ($price === null) {
            return new EventCost(null, $model);
        }

        $microUsd = $this->calculator->costMicroUsd($usage, $price);

        return new EventCost($microUsd, null);
    }
}
