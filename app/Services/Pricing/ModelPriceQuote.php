<?php

namespace App\Services\Pricing;

use App\Models\ModelPrice;

/**
 * One model's price as read from the feed, in USD per million tokens.
 */
final readonly class ModelPriceQuote
{
    /**
     * Hold one parsed price.
     *
     * @param  string  $model  the feed key the price was read from
     * @param  string  $provider  `anthropic` or `openai`
     * @param  float  $input  input price per MTok
     * @param  float  $output  output price per MTok
     * @param  float  $cacheRead  cache-read price per MTok
     * @param  float  $cacheWrite5m  5-minute cache-write price per MTok
     * @param  ?float  $cacheWrite1h  1-hour cache-write price per MTok, null when the vendor has none
     */
    public function __construct(
        public string $model,
        public string $provider,
        public float $input,
        public float $output,
        public float $cacheRead,
        public float $cacheWrite5m,
        public ?float $cacheWrite1h,
    ) {}

    /**
     * The same price, to be stored under another model id.
     *
     * @param  string  $model  the model id to store it under
     * @return self
     */
    public function withModel(string $model): self
    {
        return new self($model, $this->provider, $this->input, $this->output, $this->cacheRead, $this->cacheWrite5m, $this->cacheWrite1h);
    }

    /**
     * Whether a stored row already carries exactly this price.
     *
     * @param  ModelPrice  $row  the model's latest stored price
     * @return bool
     */
    public function matches(ModelPrice $row): bool
    {
        return $this->provider === $row->provider
            && $this->input === $row->input_per_mtok
            && $this->output === $row->output_per_mtok
            && $this->cacheRead === $row->cache_read_per_mtok
            && $this->cacheWrite5m === $row->cache_write_5m_per_mtok
            && $this->cacheWrite1h === $row->cache_write_1h_per_mtok;
    }

    /**
     * The price columns of a `model_prices` row.
     *
     * @return array{provider: string, input_per_mtok: float, output_per_mtok: float, cache_read_per_mtok: float, cache_write_5m_per_mtok: float, cache_write_1h_per_mtok: ?float}
     */
    public function priceColumns(): array
    {
        return [
            'provider' => $this->provider,
            'input_per_mtok' => $this->input,
            'output_per_mtok' => $this->output,
            'cache_read_per_mtok' => $this->cacheRead,
            'cache_write_5m_per_mtok' => $this->cacheWrite5m,
            'cache_write_1h_per_mtok' => $this->cacheWrite1h,
        ];
    }
}
