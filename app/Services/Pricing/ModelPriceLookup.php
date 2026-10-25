<?php

namespace App\Services\Pricing;

use App\Models\ModelPrice;
use App\Support\CacheKeys;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Finds the price row to cost an event with: the current one for live
 * ingest (cached), or the one in force on a past day for stored events.
 */
class ModelPriceLookup
{
    /**
     * Cached stand-in for "this model has no price", since a cached `null`
     * reads back as a cache miss.
     *
     * @var string
     */
    private const string NO_PRICE = 'none';

    /**
     * The model's latest price row, or null when it has none.
     *
     * Cached per model for `token_slayer.model_prices.cache_ttl_seconds`
     * (1h), absence included. The row's raw attributes are cached, not the
     * model: the cache stores unserialize with no classes allowed
     * (`cache.serializable_classes`), which would turn a cached model into an
     * incomplete class. Nothing invalidates the key: a price change or
     * a newly priced model reaches ingest when the key expires, an accepted
     * lag of up to 1h. Events priced in that window keep the old price, and
     * events left without a price are healed by the hourly missing-model fill.
     *
     * @param  string  $model  the raw model id from the event
     * @return ?ModelPrice
     */
    public function current(string $model): ?ModelPrice
    {
        $ttlSeconds = config('token_slayer.model_prices.cache_ttl_seconds');

        $cached = Cache::remember(
            CacheKeys::modelPrice($model),
            $ttlSeconds,
            fn (): array|string => $this->latestRow($model)?->getAttributes() ?? self::NO_PRICE,
        );

        return is_array($cached) ? (new ModelPrice)->newFromBuilder($cached) : null;
    }

    /**
     * The row in force on the given moment's UTC day: the latest row starting
     * on or before it, else the model's earliest row (an event older than the
     * first known price takes that price). Uncached; stored-event pricing
     * memoises per day itself.
     *
     * @param  string  $model  the raw model id from the event
     * @param  Carbon  $at  when the event happened
     * @return ?ModelPrice
     */
    public function effectiveOn(string $model, Carbon $at): ?ModelPrice
    {
        $day = $at->copy()->utc()->toDateString();

        foreach (ModelPrice::candidateIds($model) as $candidateId) {
            $inForce = ModelPrice::query()
                ->where('model', $candidateId)
                ->whereDate('effective_from', '<=', $day)
                ->orderByDesc('effective_from')
                ->first();

            $price = $inForce ?? ModelPrice::query()
                ->where('model', $candidateId)
                ->orderBy('effective_from')
                ->first();

            if ($price !== null) {
                return $price;
            }
        }

        return null;
    }

    /**
     * The latest row of the first candidate id that has any row.
     *
     * @param  string  $model  the raw model id from the event
     * @return ?ModelPrice
     */
    private function latestRow(string $model): ?ModelPrice
    {
        foreach (ModelPrice::candidateIds($model) as $candidateId) {
            $price = ModelPrice::query()
                ->where('model', $candidateId)
                ->orderByDesc('effective_from')
                ->first();

            if ($price !== null) {
                return $price;
            }
        }

        return null;
    }
}
