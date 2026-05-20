<?php

use App\Services\FighterChargingCache;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

test('put stores the activity and started_at under the per-user key', function () {
    $cache = app(FighterChargingCache::class);

    $cache->put(42, 'Bash: npm install');

    $raw = Cache::get('fighter-charging:42');
    expect($raw)->toBeArray()
        ->and($raw['activity'])->toBe('Bash: npm install')
        ->and($raw['started_at'])->toBeString();
});

test('put refreshes the TTL on each call', function () {
    $cache = app(FighterChargingCache::class);

    $cache->put(42, 'thinking…');
    $cache->put(42, 'Bash: ls');

    expect(Cache::get('fighter-charging:42')['activity'])->toBe('Bash: ls');
});

test('forget clears the per-user key', function () {
    $cache = app(FighterChargingCache::class);
    $cache->put(42, 'thinking…');

    $cache->forget(42);

    expect(Cache::has('fighter-charging:42'))->toBeFalse();
});

test('many returns payloads keyed by user id, with null for missing entries', function () {
    $cache = app(FighterChargingCache::class);
    $cache->put(1, 'thinking…');
    $cache->put(3, 'Bash: pwd');

    $result = $cache->many([1, 2, 3]);

    expect($result)->toHaveKeys([1, 2, 3])
        ->and($result[1]['activity'])->toBe('thinking…')
        ->and($result[2])->toBeNull()
        ->and($result[3]['activity'])->toBe('Bash: pwd');
});

test('many returns empty array for empty input', function () {
    expect(app(FighterChargingCache::class)->many([]))->toBe([]);
});
