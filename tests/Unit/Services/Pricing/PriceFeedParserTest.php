<?php

use App\Services\Pricing\ModelPriceQuote;
use App\Services\Pricing\PriceFeedParser;

/**
 * The captured feed subset, decoded.
 *
 * @return array<string, mixed>
 */
function feedFixture(): array
{
    return json_decode(file_get_contents(base_path('tests/fixtures/litellm/model_prices.json')), true);
}

it('parses our models into per-MTok quotes', function () {
    $quotes = (new PriceFeedParser)->parse(feedFixture(), ['claude-sonnet-5-5', 'gpt-6-sol']);

    expect($quotes['claude-sonnet-5-5'])->toEqual(new ModelPriceQuote('claude-sonnet-5-5', 'anthropic', 2.0, 10.0, 0.1, 2.5, 4.0))
        ->and($quotes['gpt-6-sol'])->toEqual(new ModelPriceQuote('gpt-6-sol', 'openai', 2.0, 10.0, 0.2, 2.5, null));
});

it('reads only the wanted keys', function () {
    $quotes = (new PriceFeedParser)->parse(feedFixture(), ['claude-sonnet-5-5']);

    expect(array_keys($quotes))->toBe(['claude-sonnet-5-5']);
});

it('ignores an entry from another provider even when wanted', function () {
    $quotes = (new PriceFeedParser)->parse(feedFixture(), ['bedrock/us-gov-east-1/anthropic.claude-sonnet-5-5', 'azure/eu/text-embedding-3-small']);

    expect($quotes)->toBe([]);
});

it('skips only the model whose entry breaks a rule', function (string $field, mixed $value) {
    $feed = feedFixture();
    $feed['claude-sonnet-5-5'][$field] = $value;

    $quotes = (new PriceFeedParser)->parse($feed, ['claude-sonnet-5-5', 'gpt-6-sol']);

    expect(array_keys($quotes))->toBe(['gpt-6-sol']);
})->with([
    'missing' => ['cache_creation_input_token_cost_above_1hr', null],
    'negative' => ['input_cost_per_token', -0.000001],
    'string' => ['output_cost_per_token', '0.00001'],
    'infinite' => ['input_cost_per_token', INF],
    'above column max' => ['output_cost_per_token', 1.0],
]);

it('needs only input, output and cache read for an openai model', function () {
    $feed = feedFixture();
    unset($feed['gpt-6-sol']['cache_creation_input_token_cost']);

    $quotes = (new PriceFeedParser)->parse($feed, ['gpt-6-sol']);

    expect($quotes['gpt-6-sol']->cacheWrite5m)->toBe(0.0)
        ->and($quotes['gpt-6-sol']->cacheWrite1h)->toBeNull();
});

it('skips an entry that is not an object', function () {
    $quotes = (new PriceFeedParser)->parse(['claude-sonnet-5-5' => 'x'], ['claude-sonnet-5-5']);

    expect($quotes)->toBe([]);
});
