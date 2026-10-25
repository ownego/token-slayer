<?php

use App\Exceptions\ModelPriceFeedException;
use App\Models\MissingModel;
use App\Models\ModelPrice;
use App\Models\PriceFeedState;
use App\Services\Pricing\ModelPriceSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->feedUrl = config('token_slayer.model_prices.feed_url');
    $this->feed = json_decode(file_get_contents(base_path('tests/fixtures/litellm/model_prices.json')), true);
    $this->travelTo('2026-10-24 10:00:00');
});

/**
 * Serve the given feed body with an ETag.
 *
 * @param  array<string, mixed>  $feed  the decoded feed to serve
 * @return void
 */
function serveFeed(array $feed): void
{
    Http::fake([config('token_slayer.model_prices.feed_url') => Http::response(json_encode($feed), 200, ['ETag' => '"v1"'])]);
}

it('prices the seeded history models on a fresh database', function () {
    serveFeed($this->feed);

    $result = app(ModelPriceSyncer::class)->sync();

    expect(ModelPrice::where('model', 'claude-sonnet-5-5')->count())->toBe(2)
        ->and($result->changedModels)->toBe(0)
        ->and(PriceFeedState::sole()->etag)->toBe('"v1"');
});

it('adds a row for a missing model and only for wanted models', function () {
    MissingModel::factory()->create(['model' => 'gpt-6-sol']);
    serveFeed($this->feed);

    $result = app(ModelPriceSyncer::class)->sync();

    expect($result->changedModels)->toBe(1)
        ->and(ModelPrice::where('model', 'gpt-6-sol')->sole()->effective_from->toDateString())->toBe('2026-10-24')
        ->and(ModelPrice::where('model', 'gpt-6-sol')->sole()->source_sha256)->toBe(hash('sha256', json_encode($this->feed)))
        ->and(ModelPrice::where('model', 'gpt-5.6-sol')->exists())->toBeFalse();
});

it('inserts nothing when the feed repeats the stored prices', function () {
    MissingModel::factory()->create(['model' => 'gpt-6-sol']);
    serveFeed($this->feed);
    app(ModelPriceSyncer::class)->sync();

    $result = app(ModelPriceSyncer::class)->sync();

    expect($result->changedModels)->toBe(0)
        ->and(ModelPrice::count())->toBe(3);
});

it('adds a row dated today when a price changes', function () {
    $this->feed['claude-sonnet-5-5']['cache_read_input_token_cost'] = 0.00000005;
    serveFeed($this->feed);

    app(ModelPriceSyncer::class)->sync();

    expect(ModelPrice::where('model', 'claude-sonnet-5-5')->orderByDesc('effective_from')->first())
        ->effective_from->toDateString()->toBe('2026-10-24')
        ->cache_read_per_mtok->toBe(0.05);
});

it('prefers the exact feed key of a dated model over its undated twin', function () {
    MissingModel::factory()->create(['model' => 'claude-haiku-4-5-20251001']);
    $this->feed['claude-haiku-4-5']['input_cost_per_token'] = 0.000009;
    serveFeed($this->feed);

    app(ModelPriceSyncer::class)->sync();
    app(ModelPriceSyncer::class)->sync();

    expect(ModelPrice::where('model', 'like', 'claude-haiku%')->pluck('input_per_mtok', 'model')->all())
        ->toBe(['claude-haiku-4-5-20251001' => 1.0]);
});

it('only touches the check time on 304', function () {
    PriceFeedState::create(['feed' => 'litellm', 'etag' => '"v1"']);
    Http::fake([$this->feedUrl => Http::response('', 304)]);

    $result = app(ModelPriceSyncer::class)->sync();

    expect($result->notModified)->toBeTrue()
        ->and(ModelPrice::where('source_sha256', '!=', null)->count())->toBe(0)
        ->and(PriceFeedState::sole()->checked_at->toDateTimeString())->toBe('2026-10-24 10:00:00');
});

it('changes no price when the feed fails', function () {
    MissingModel::factory()->create(['model' => 'gpt-6-sol']);
    Http::fake([$this->feedUrl => Http::response('', 500)]);

    expect(fn () => app(ModelPriceSyncer::class)->sync())->toThrow(ModelPriceFeedException::class)
        ->and(ModelPrice::where('model', 'gpt-6-sol')->exists())->toBeFalse();
});

it('prices an undated model from its newest dated feed key when the feed has no undated key', function () {
    MissingModel::factory()->create(['model' => 'claude-haiku-4-5']);
    unset($this->feed['claude-haiku-4-5']);
    $this->feed['claude-haiku-4-5-20250101'] = $this->feed['claude-haiku-4-5-20251001'];
    $this->feed['claude-haiku-4-5-20250101']['input_cost_per_token'] = 0.000009;
    serveFeed($this->feed);

    app(ModelPriceSyncer::class)->sync();

    expect(ModelPrice::where('model', 'like', 'claude-haiku%')->pluck('input_per_mtok', 'model')->all())
        ->toBe(['claude-haiku-4-5' => 1.0]);
});
