<?php

use App\Services\FighterPositionCache;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

test('put stores x and y under the per-user key', function () {
    $cache = app(FighterPositionCache::class);

    $cache->put(42, 0.5, 0.75);

    $raw = Cache::get('fighter-position:42');
    expect($raw)->toBeArray()
        ->and($raw['x'])->toBe(0.5)
        ->and($raw['y'])->toBe(0.75);
});

test('put overwrites previous position on second call', function () {
    $cache = app(FighterPositionCache::class);
    $cache->put(42, 0.1, 0.6);
    $cache->put(42, 0.9, 0.8);

    expect(Cache::get('fighter-position:42'))->toBe(['x' => 0.9, 'y' => 0.8]);
});

test('get returns stored position for known user', function () {
    $cache = app(FighterPositionCache::class);
    $cache->put(7, 0.3, 0.7);

    expect($cache->get(7))->toBe(['x' => 0.3, 'y' => 0.7]);
});

test('get returns null for unknown user', function () {
    expect(app(FighterPositionCache::class)->get(99))->toBeNull();
});

test('many returns positions keyed by user id with null for missing', function () {
    $cache = app(FighterPositionCache::class);
    $cache->put(1, 0.2, 0.6);
    $cache->put(3, 0.8, 0.9);

    $result = $cache->many([1, 2, 3]);

    expect($result)->toHaveKeys([1, 2, 3])
        ->and($result[1])->toBe(['x' => 0.2, 'y' => 0.6])
        ->and($result[2])->toBeNull()
        ->and($result[3])->toBe(['x' => 0.8, 'y' => 0.9]);
});

test('many returns empty array for empty input', function () {
    expect(app(FighterPositionCache::class)->many([]))->toBe([]);
});
