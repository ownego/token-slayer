<?php

namespace App\Services\Pricing;

use App\Models\Event;
use App\Models\MissingModel;
use App\Models\ModelPrice;

/**
 * Prices the stored events of models that were missing a price, once the
 * price exists. The only code that prices stored events: the hourly sync and
 * the one-off backfill both go through it.
 */
final class MissingModelCostFiller
{
    /**
     * Events read and written per chunk.
     *
     * @var int
     */
    private const int CHUNK_SIZE = 500;

    /**
     * Build the filler from the lookup and the formula.
     *
     * @param  ModelPriceLookup  $prices  finds the price in force on an event's day
     * @param  EventCostCalculator  $calculator  the cost formula
     */
    public function __construct(
        private ModelPriceLookup $prices,
        private EventCostCalculator $calculator,
    ) {}

    /**
     * Price the unpriced complete events of every missing model that now has
     * a price, then drop its row.
     *
     * The row is dropped only if nothing touched it after this model's fill
     * began: ingest records a missing model after writing the event, so an
     * event this fill may have missed always leaves its row for the next run.
     *
     * @return int the number of events priced
     */
    public function fill(): int
    {
        $filledEvents = 0;

        foreach (MissingModel::query()->get() as $missing) {
            if ($this->prices->effectiveOn($missing->model, now()) === null) {
                continue;
            }

            $startedAt = now();
            $filledEvents += $this->fillModel($missing->model);

            MissingModel::query()
                ->whereKey($missing->id)
                ->where('last_seen_at', '<', $startedAt)
                ->delete();
        }

        return $filledEvents;
    }

    /**
     * Price one model's unpriced complete events, each at the price of its
     * own UTC day (looked up once per day within the run).
     *
     * @param  string  $model  the raw model id on the events
     * @return int the number of events priced
     */
    private function fillModel(string $model): int
    {
        /** @var array<string, ModelPrice> $pricesByDay */
        $pricesByDay = [];
        $filledEvents = 0;

        Event::query()
            ->select(['id', 'provider', 'model', 'tokens', 'input_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens', 'session_id', 'created_at'])
            ->where('model', $model)
            ->whereNull('cost_micro_usd')
            ->whereIn('provider', PricedUsage::PRICED_PROVIDERS)
            ->chunkById(self::CHUNK_SIZE, function ($events) use ($model, &$pricesByDay, &$filledEvents): void {
                foreach ($events as $event) {
                    $usage = PricedUsage::fromEvent($event);

                    if (! $usage->hasFullData()) {
                        continue;
                    }

                    $day = $event->created_at->copy()->utc()->toDateString();
                    $price = $pricesByDay[$day] ??= $this->prices->effectiveOn($model, $event->created_at);
                    $microUsd = $this->calculator->costMicroUsd($usage, $price);

                    Event::query()->whereKey($event->id)->update(['cost_micro_usd' => $microUsd]);
                    $filledEvents++;
                }
            });

        return $filledEvents;
    }
}
