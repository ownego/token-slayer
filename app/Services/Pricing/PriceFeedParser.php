<?php

namespace App\Services\Pricing;

use App\Models\ModelPrice;

/**
 * Reads our models' prices out of the decoded feed. Pure. The feed is an
 * outside source: only the wanted keys and five numeric fields are read,
 * and an entry breaking any rule is skipped whole so that model keeps its
 * previous price.
 */
final class PriceFeedParser
{
    /**
     * Feed providers we accept. Hosted copies of the same models (Bedrock,
     * Vertex, Azure) carry other prices and are ignored.
     *
     * @var list<string>
     */
    private const array PROVIDERS = ['anthropic', 'openai'];

    /**
     * Feed values are USD per token; stored prices are USD per MTok.
     *
     * @var int
     */
    private const int TOKENS_PER_MTOK = 1_000_000;

    /**
     * Decimal places stored per price, matching `decimal(12,6)`.
     *
     * @var int
     */
    private const int PRICE_SCALE = 6;

    /**
     * Quotes for the wanted ids that the feed prices validly, keyed by feed key.
     *
     * @param  array<array-key, mixed>  $entries  the decoded feed object
     * @param  list<string>  $wantedIds  the feed keys to read
     * @return array<string, ModelPriceQuote>
     */
    public function parse(array $entries, array $wantedIds): array
    {
        $quotes = [];

        foreach ($wantedIds as $wantedId) {
            $entry = $entries[$wantedId] ?? null;
            $quote = is_array($entry) ? $this->quote($wantedId, $entry) : null;

            if ($quote !== null) {
                $quotes[$wantedId] = $quote;
            }
        }

        return $quotes;
    }

    /**
     * One entry's quote, or null when it breaks a rule. Anthropic needs all
     * five prices; OpenAI needs input, output and cache read (it bills no
     * cache writes and has no 1h tier).
     *
     * @param  string  $key  the feed key
     * @param  array<array-key, mixed>  $entry  the feed entry
     * @return ?ModelPriceQuote
     */
    private function quote(string $key, array $entry): ?ModelPriceQuote
    {
        $provider = $entry['litellm_provider'] ?? null;

        if (! in_array($provider, self::PROVIDERS, true)) {
            return null;
        }

        $input = $this->perMtok($entry['input_cost_per_token'] ?? null);
        $output = $this->perMtok($entry['output_cost_per_token'] ?? null);
        $cacheRead = $this->perMtok($entry['cache_read_input_token_cost'] ?? null);

        if ($input === null || $output === null || $cacheRead === null) {
            return null;
        }

        if ($provider === 'openai') {
            $cacheWrite5m = array_key_exists('cache_creation_input_token_cost', $entry)
                ? $this->perMtok($entry['cache_creation_input_token_cost'])
                : 0.0;

            return $cacheWrite5m === null ? null : new ModelPriceQuote($key, $provider, $input, $output, $cacheRead, $cacheWrite5m, null);
        }

        $cacheWrite5m = $this->perMtok($entry['cache_creation_input_token_cost'] ?? null);
        $cacheWrite1h = $this->perMtok($entry['cache_creation_input_token_cost_above_1hr'] ?? null);

        if ($cacheWrite5m === null || $cacheWrite1h === null) {
            return null;
        }

        return new ModelPriceQuote($key, $provider, $input, $output, $cacheRead, $cacheWrite5m, $cacheWrite1h);
    }

    /**
     * A per-token value as a per-MTok price, or null unless it is a finite
     * number from zero to the largest storable price.
     *
     * @param  mixed  $perToken  the raw feed value
     * @return ?float
     */
    private function perMtok(mixed $perToken): ?float
    {
        if (! is_int($perToken) && ! is_float($perToken)) {
            return null;
        }

        $price = round($perToken * self::TOKENS_PER_MTOK, self::PRICE_SCALE);

        if (! is_finite($price) || $price < 0 || $price > ModelPrice::MAX_PRICE_PER_MTOK) {
            return null;
        }

        return $price;
    }
}
