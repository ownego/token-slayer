<?php

use App\Models\ModelPrice;
use App\Services\Pricing\ModelPriceLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

it('returns the latest price row of a model', function () {
    ModelPrice::factory()->create(['effective_from' => '2026-09-01', 'cache_read_per_mtok' => 0.20]);
    ModelPrice::factory()->create(['effective_from' => '2026-10-07', 'cache_read_per_mtok' => 0.10]);

    expect(app(ModelPriceLookup::class)->current('claude-sonnet-5-5')->cache_read_per_mtok)->toBe(0.10);
});

it('serves a found price from the cache until it expires', function () {
    ModelPrice::factory()->create();
    $lookup = app(ModelPriceLookup::class);
    $lookup->current('claude-sonnet-5-5');

    ModelPrice::query()->delete();

    expect($lookup->current('claude-sonnet-5-5'))->not->toBeNull();
});

it('caches a missing price too, so a new row shows only after the cache expires', function () {
    $lookup = app(ModelPriceLookup::class);
    expect($lookup->current('claude-sonnet-5-5'))->toBeNull();

    ModelPrice::factory()->create();
    expect($lookup->current('claude-sonnet-5-5'))->toBeNull();

    Cache::flush();
    expect($lookup->current('claude-sonnet-5-5'))->not->toBeNull();
});

it('finds a dated model id under its undated price row', function () {
    ModelPrice::factory()->create(['model' => 'claude-haiku-4-5']);

    expect(app(ModelPriceLookup::class)->current('claude-haiku-4-5-20251001')?->model)->toBe('claude-haiku-4-5');
});

it('picks the price in force on a given day', function (string $day, float $expected) {
    ModelPrice::factory()->create(['effective_from' => '2026-09-01', 'cache_read_per_mtok' => 0.20]);
    ModelPrice::factory()->create(['effective_from' => '2026-10-07', 'cache_read_per_mtok' => 0.10]);

    $price = app(ModelPriceLookup::class)->effectiveOn('claude-sonnet-5-5', Carbon::parse($day));

    expect($price->cache_read_per_mtok)->toBe($expected);
})->with([
    'the day before the change' => ['2026-10-06 23:59:59', 0.20],
    'the day of the change' => ['2026-10-07 00:00:00', 0.10],
    'before the earliest row falls back to it' => ['2026-08-01 12:00:00', 0.20],
]);

it('returns null for a model with no price at any date', function () {
    expect(app(ModelPriceLookup::class)->effectiveOn('gpt-unknown', now()))->toBeNull();
});

it('serves a cached price from a store that serializes without allowing classes, as production stores do', function () {
    // config/cache.php sets serializable_classes=false, so Redis/database
    // stores unserialize objects as incomplete classes; the array store in
    // phpunit does not serialize at all and hides that.
    config(['cache.stores.serializing' => ['driver' => 'array', 'serialize' => true]]);
    config(['cache.default' => 'serializing']);
    ModelPrice::factory()->create();
    $lookup = app(ModelPriceLookup::class);

    $first = $lookup->current('claude-sonnet-5-5');
    $second = $lookup->current('claude-sonnet-5-5');

    expect($first)->toBeInstanceOf(ModelPrice::class)
        ->and($second)->toBeInstanceOf(ModelPrice::class)
        ->and($second->cache_read_per_mtok)->toBe(0.10)
        ->and($second->effective_from->toDateString())->toBe('2026-09-01');
});
