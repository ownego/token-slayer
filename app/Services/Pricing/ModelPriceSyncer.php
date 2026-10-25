<?php

namespace App\Services\Pricing;

use App\Exceptions\ModelPriceFeedException;
use App\Models\MissingModel;
use App\Models\ModelPrice;
use App\Models\PriceFeedState;

/**
 * Brings stored prices up to date with the feed, then prices the events of
 * models that were missing a price.
 */
final class ModelPriceSyncer
{
    /**
     * Name of the feed's row in `price_feed_states`.
     *
     * @var string
     */
    private const string FEED = 'litellm';

    /**
     * Build the syncer from its steps.
     *
     * @param  ModelPriceHistorySeeder  $history  loads dated prices before the first sync
     * @param  LiteLlmPriceFeed  $feed  the hardened fetch
     * @param  PriceFeedParser  $parser  reads our models' prices from the feed
     * @param  MissingModelCostFiller  $filler  prices events of newly priced models
     */
    public function __construct(
        private ModelPriceHistorySeeder $history,
        private LiteLlmPriceFeed $feed,
        private PriceFeedParser $parser,
        private MissingModelCostFiller $filler,
    ) {}

    /**
     * Seed history, fetch, store changed prices, fill missing-model events.
     *
     * A model gets a new row dated today (UTC) only when its price differs
     * from its latest row, so stored costs keep the price of their own day.
     * A feed failure changes no price and skips the fill.
     *
     * @return PriceSyncResult
     *
     * @throws ModelPriceFeedException
     */
    public function sync(): PriceSyncResult
    {
        $this->history->seed();

        $state = PriceFeedState::query()->firstOrCreate(['feed' => self::FEED]);
        $response = $this->feed->fetch($this->conditionalEtag($state));

        if ($response->notModified) {
            $state->update(['checked_at' => now()]);
            $filledEvents = $this->filler->fill();

            return new PriceSyncResult(true, 0, $filledEvents);
        }

        $changedModels = $this->storeChangedPrices($response);

        $state->update([
            'etag' => $response->etag,
            'sha256' => $response->sha256,
            'checked_at' => now(),
            'changed_at' => $changedModels > 0 ? now() : $state->changed_at,
        ]);

        $filledEvents = $this->filler->fill();

        return new PriceSyncResult(false, $changedModels, $filledEvents);
    }

    /**
     * Write a row for every tracked model whose feed price changed.
     *
     * A tracked model takes the quote of its exact feed key, else of its
     * undated key, else of its newest dated key (an undated id the feed
     * lists only with a date stamp); the last is stored under the tracked
     * id so the lookup finds it. One quote per row, so a model never flips
     * between two upstream prices.
     *
     * @param  PriceFeedResponse  $response  a fetched (modified) feed
     * @return int the number of models that got a new row
     */
    private function storeChangedPrices(PriceFeedResponse $response): int
    {
        $trackedModels = $this->trackedModels();
        $feedKeys = array_map('strval', array_keys($response->entries));
        $keysByModel = [];

        foreach ($trackedModels as $model) {
            $keysByModel[$model] = [...ModelPrice::candidateIds($model), ...$this->newestDatedKey($model, $feedKeys)];
        }

        $wantedIds = array_values(array_unique(array_merge(...array_values($keysByModel))));
        $quotes = $this->parser->parse($response->entries, $wantedIds);
        $chosenQuotes = [];

        foreach ($keysByModel as $model => $keys) {
            $quote = $this->firstQuote($keys, $quotes);

            if ($quote === null) {
                continue;
            }

            $storedQuote = in_array($quote->model, ModelPrice::candidateIds($model), true) ? $quote : $quote->withModel($model);
            $chosenQuotes[$storedQuote->model] = $storedQuote;
        }

        $changedModels = 0;

        foreach ($chosenQuotes as $quote) {
            if ($this->storeIfChanged($quote, (string) $response->sha256)) {
                $changedModels++;
            }
        }

        return $changedModels;
    }

    /**
     * The quote of the first key that has one.
     *
     * @param  list<string>  $keys  feed keys in order of preference
     * @param  array<string, ModelPriceQuote>  $quotes  parsed quotes by feed key
     * @return ?ModelPriceQuote
     */
    private function firstQuote(array $keys, array $quotes): ?ModelPriceQuote
    {
        foreach ($keys as $key) {
            if (isset($quotes[$key])) {
                return $quotes[$key];
            }
        }

        return null;
    }

    /**
     * The feed's newest `<model>-YYYYMMDD` key, as a one-item list, or an
     * empty list when the feed has none.
     *
     * @param  string  $model  a tracked model id
     * @param  list<string>  $feedKeys  every key of the feed
     * @return list<string>
     */
    private function newestDatedKey(string $model, array $feedKeys): array
    {
        $pattern = '/\A'.preg_quote($model, '/').'-\d{8}\z/';
        $datedKeys = preg_grep($pattern, $feedKeys) ?: [];

        if ($datedKeys === []) {
            return [];
        }

        rsort($datedKeys);

        return [$datedKeys[0]];
    }

    /**
     * Store the quote as today's row unless the latest row already matches.
     *
     * @param  ModelPriceQuote  $quote  one parsed price
     * @param  string  $sha256  hash of the feed file it came from
     * @return bool whether a row was written
     */
    private function storeIfChanged(ModelPriceQuote $quote, string $sha256): bool
    {
        $latest = ModelPrice::query()
            ->where('model', $quote->model)
            ->orderByDesc('effective_from')
            ->first();

        if ($latest !== null && $quote->matches($latest)) {
            return false;
        }

        $today = now()->utc()->toDateString();

        $todaysRow = ModelPrice::query()
            ->where('model', $quote->model)
            ->whereDate('effective_from', $today)
            ->first() ?? new ModelPrice(['model' => $quote->model, 'effective_from' => $today]);

        $todaysRow->fill([...$quote->priceColumns(), 'source_sha256' => $sha256])->save();

        return true;
    }

    /**
     * The stored ETag, or null while a model waits for a price.
     *
     * The stored file was parsed only for the models tracked at the time, so
     * a model queued since then may be in it unread; a 304 would skip it
     * until the upstream file changes.
     *
     * @param  PriceFeedState  $state  the feed's stored state
     * @return ?string
     */
    private function conditionalEtag(PriceFeedState $state): ?string
    {
        return MissingModel::query()->exists() ? null : $state->etag;
    }

    /**
     * Every model id that should be priced: those already priced and those
     * seen without a price.
     *
     * @return list<string>
     */
    private function trackedModels(): array
    {
        $pricedModels = ModelPrice::query()->distinct()->pluck('model')->all();
        $missingModels = MissingModel::query()->pluck('model')->all();

        return array_values(array_unique([...$pricedModels, ...$missingModels]));
    }
}
