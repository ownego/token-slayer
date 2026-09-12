<?php

use App\Models\FighterPosition;
use App\Models\User;
use App\Services\FighterPositionCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

test('put stores x and y under the per-user key', function () {
    $user = User::factory()->create();
    $cache = app(FighterPositionCache::class);

    $cache->put($user->id, 0.5, 0.75);

    $raw = Cache::get("fighter-position:{$user->id}");
    expect($raw)->toBeArray()
        ->and($raw['x'])->toBe(0.5)
        ->and($raw['y'])->toBe(0.75);
});

test('put overwrites previous position on second call', function () {
    $user = User::factory()->create();
    $cache = app(FighterPositionCache::class);
    $cache->put($user->id, 0.1, 0.6);
    $cache->put($user->id, 0.9, 0.8);

    expect(Cache::get("fighter-position:{$user->id}"))->toBe(['x' => 0.9, 'y' => 0.8]);
});

test('get returns stored position for known user', function () {
    $user = User::factory()->create();
    $cache = app(FighterPositionCache::class);
    $cache->put($user->id, 0.3, 0.7);

    expect($cache->get($user->id))->toBe(['x' => 0.3, 'y' => 0.7]);
});

test('get returns null for unknown user', function () {
    $user = User::factory()->create();

    expect(app(FighterPositionCache::class)->get($user->id))->toBeNull();
});

test('many returns positions keyed by user id with null for missing', function () {
    $users = User::factory()->count(3)->create();
    [$u1, $u2, $u3] = $users;
    $cache = app(FighterPositionCache::class);
    $cache->put($u1->id, 0.2, 0.6);
    $cache->put($u3->id, 0.8, 0.9);

    $result = $cache->many([$u1->id, $u2->id, $u3->id]);

    expect($result)->toHaveKeys([$u1->id, $u2->id, $u3->id])
        ->and($result[$u1->id])->toBe(['x' => 0.2, 'y' => 0.6])
        ->and($result[$u2->id])->toBeNull()
        ->and($result[$u3->id])->toBe(['x' => 0.8, 'y' => 0.9]);
});

test('many returns empty array for empty input', function () {
    expect(app(FighterPositionCache::class)->many([]))->toBe([]);
});

test('put keeps the position forever, surviving past the old idle-minutes expiration window', function () {
    $user = User::factory()->create();
    $cache = app(FighterPositionCache::class);
    $cache->put($user->id, 0.5, 0.75);

    $this->travel(config('game.idle_minutes') + 1)->minutes();

    expect($cache->get($user->id))->toBe(['x' => 0.5, 'y' => 0.75]);
});

test('put persists the position to the fighter_positions table, not only the cache', function () {
    $user = User::factory()->create();

    app(FighterPositionCache::class)->put($user->id, 0.5, 0.75);

    $row = FighterPosition::query()->find($user->id);
    expect($row)->not->toBeNull()
        ->and($row->x)->toBe(0.5)
        ->and($row->y)->toBe(0.75);
});

test('put upserts the same row on a second call for the same user', function () {
    $user = User::factory()->create();
    $cache = app(FighterPositionCache::class);
    $cache->put($user->id, 0.1, 0.2);
    $cache->put($user->id, 0.9, 0.8);

    expect(FighterPosition::query()->count())->toBe(1)
        ->and(FighterPosition::query()->find($user->id)->only(['x', 'y']))->toBe(['x' => 0.9, 'y' => 0.8]);
});

test('get falls back to the database when the cache is empty, and backfills the cache', function () {
    $user = User::factory()->create();
    FighterPosition::factory()->create(['user_id' => $user->id, 'x' => 0.4, 'y' => 0.6]);

    $position = app(FighterPositionCache::class)->get($user->id);

    expect($position)->toBe(['x' => 0.4, 'y' => 0.6])
        ->and(Cache::get("fighter-position:{$user->id}"))->toBe(['x' => 0.4, 'y' => 0.6]);
});

test('get returns null when neither the cache nor the database has the user', function () {
    $user = User::factory()->create();

    expect(app(FighterPositionCache::class)->get($user->id))->toBeNull();
});

test('many falls back to the database per-user for cache misses, and backfills the cache', function () {
    $users = User::factory()->count(3)->create();
    [$u1, $u2, $u3] = $users;
    $cache = app(FighterPositionCache::class);
    $cache->put($u1->id, 0.2, 0.6); // cache-hot
    FighterPosition::factory()->create(['user_id' => $u2->id, 'x' => 0.11, 'y' => 0.22]); // DB-only
    // $u3: neither cache nor DB

    $result = $cache->many([$u1->id, $u2->id, $u3->id]);

    expect($result[$u1->id])->toBe(['x' => 0.2, 'y' => 0.6])
        ->and($result[$u2->id])->toBe(['x' => 0.11, 'y' => 0.22])
        ->and($result[$u3->id])->toBeNull()
        ->and(Cache::get("fighter-position:{$u2->id}"))->toBe(['x' => 0.11, 'y' => 0.22]);
});
