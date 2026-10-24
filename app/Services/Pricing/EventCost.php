<?php

namespace App\Services\Pricing;

/**
 * What ingest stores for one turn's cost.
 */
final readonly class EventCost
{
    /**
     * Hold the outcome of pricing one turn.
     *
     * @param  ?int  $microUsd  the cost in micro-dollars, null when the turn cannot be priced
     * @param  ?string  $missingModel  the model to queue for the price sync, set only for a complete turn with no price
     */
    public function __construct(
        public ?int $microUsd,
        public ?string $missingModel,
    ) {}
}
