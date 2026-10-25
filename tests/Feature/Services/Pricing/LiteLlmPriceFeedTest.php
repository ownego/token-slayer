<?php

use App\Exceptions\ModelPriceFeedException;
use App\Services\Pricing\LiteLlmPriceFeed;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->feedUrl = config('token_slayer.model_prices.feed_url');
    $this->body = file_get_contents(base_path('tests/fixtures/litellm/model_prices.json'));
});

it('returns the entries, the etag and the body hash', function () {
    Http::fake([$this->feedUrl => Http::response($this->body, 200, ['ETag' => '"abc"'])]);

    $response = app(LiteLlmPriceFeed::class)->fetch(null);

    expect($response->notModified)->toBeFalse()
        ->and($response->entries)->toHaveKey('claude-sonnet-5-5')
        ->and($response->etag)->toBe('"abc"')
        ->and($response->sha256)->toBe(hash('sha256', $this->body));
});

it('sends the etag back and reports not modified on 304', function () {
    Http::fake([$this->feedUrl => Http::response('', 304)]);

    $response = app(LiteLlmPriceFeed::class)->fetch('"abc"');

    expect($response->notModified)->toBeTrue()
        ->and($response->entries)->toBe([]);
    Http::assertSent(fn (Request $request): bool => $request->header('If-None-Match') === ['"abc"']);
});

it('refuses a redirect instead of following it', function () {
    Http::fake([$this->feedUrl => Http::response('', 302, ['Location' => 'https://evil.example/feed.json'])]);

    expect(fn () => app(LiteLlmPriceFeed::class)->fetch(null))->toThrow(ModelPriceFeedException::class);
    Http::assertSentCount(1);
});

it('refuses a failed response', function (Closure $fake) {
    Http::fake([$this->feedUrl => $fake]);

    expect(fn () => app(LiteLlmPriceFeed::class)->fetch(null))->toThrow(ModelPriceFeedException::class);
})->with([
    'server error' => fn () => fn () => Http::response('', 500),
    'connection error' => fn () => fn () => throw new ConnectionException('timed out'),
]);

it('refuses a body over the byte cap', function () {
    config(['token_slayer.model_prices.max_bytes' => 100]);
    Http::fake([$this->feedUrl => Http::response(str_repeat(' ', 101).'{}', 200)]);

    expect(fn () => app(LiteLlmPriceFeed::class)->fetch(null))->toThrow(ModelPriceFeedException::class);
});

it('refuses a body that is not a JSON object', function (string $body) {
    Http::fake([$this->feedUrl => Http::response($body, 200)]);

    expect(fn () => app(LiteLlmPriceFeed::class)->fetch(null))->toThrow(ModelPriceFeedException::class);
})->with([
    'invalid json' => ['{"a":'],
    'a list' => ['[1,2]'],
    'a scalar' => ['42'],
]);

it('refuses JSON nested deeper than the cap', function () {
    config(['token_slayer.model_prices.json_max_depth' => 3]);
    Http::fake([$this->feedUrl => Http::response('{"a":{"b":{"c":{"d":1}}}}', 200)]);

    expect(fn () => app(LiteLlmPriceFeed::class)->fetch(null))->toThrow(ModelPriceFeedException::class);
});

it('reports an early size abort as too large, not unreachable', function () {
    // Guzzle wraps an exception thrown from on_headers/progress in its own
    // transfer exception; the size refusal must survive that wrapping.
    Http::fake([$this->feedUrl => function (Request $request) {
        throw new RequestException(
            'aborted',
            new GuzzleHttp\Psr7\Request('GET', config('token_slayer.model_prices.feed_url')),
            null,
            new ModelPriceFeedException(ModelPriceFeedException::TOO_LARGE),
        );
    }]);

    expect(fn () => app(LiteLlmPriceFeed::class)->fetch(null))
        ->toThrow(ModelPriceFeedException::class, ModelPriceFeedException::TOO_LARGE);
});
