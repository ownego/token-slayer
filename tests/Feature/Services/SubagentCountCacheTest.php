<?php

use App\Models\User;
use App\Services\SubagentCountCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->store = [];
    $this->user = User::factory()->create();
    $this->cache = app(SubagentCountCache::class);
});

test('recordDispatch creates a pending key and returns the new total', function () {
    fakeRedis($this->store);
    $result = $this->cache->recordDispatch($this->user->id);

    expect($result['count'])->toBe(1)
        ->and($this->cache->get($this->user->id))->toBe(1);
});

test('recordDispatch called repeatedly grows the pending count', function () {
    fakeRedis($this->store);
    $this->cache->recordDispatch($this->user->id);
    $this->cache->recordDispatch($this->user->id);
    $result = $this->cache->recordDispatch($this->user->id);

    expect($result['count'])->toBe(3);
});

test('recordActivity with a fresh agent_id claims an existing pending key and returns null, since the count didn\'t change', function () {
    fakeRedis($this->store);
    $this->cache->recordDispatch($this->user->id);

    $result = $this->cache->recordActivity($this->user->id, 'agent-1');

    expect($result)->toBeNull()
        ->and($this->cache->get($this->user->id))->toBe(1);
});

test('recordActivity for the same agent_id again only refreshes the TTL and returns null, never growing the count', function () {
    fakeRedis($this->store);
    $this->cache->recordDispatch($this->user->id);
    $this->cache->recordActivity($this->user->id, 'agent-1');

    $result = $this->cache->recordActivity($this->user->id, 'agent-1');

    expect($result)->toBeNull()
        ->and($this->cache->get($this->user->id))->toBe(1);
});

test('recordActivity with no pending key to claim creates a new one and returns the new total, since this is how a previously-expired subagent re-summons itself', function () {
    fakeRedis($this->store);
    // No prior recordDispatch — simulates a subagent whose original pending
    // key already expired and later claimed, then sends another event.
    $result = $this->cache->recordActivity($this->user->id, 'agent-1');

    expect($result['count'])->toBe(1);
});

test('recordActivity claims only one pending key even when several are outstanding, still returning null', function () {
    fakeRedis($this->store);
    $this->cache->recordDispatch($this->user->id);
    $this->cache->recordDispatch($this->user->id);

    $result = $this->cache->recordActivity($this->user->id, 'agent-1');

    expect($result)->toBeNull()
        ->and($this->cache->get($this->user->id))->toBe(2);
});

test('a key past its TTL no longer counts, with no manual pruning needed', function () {
    fakeRedis($this->store);
    $this->cache->recordDispatch($this->user->id);
    $this->store = []; // simulates Redis expiring the key itself

    expect($this->cache->get($this->user->id))->toBe(0);
});

test('recordDispatch and recordActivity each return a strictly increasing seq for the same user', function () {
    fakeRedis($this->store);
    $a = $this->cache->recordActivity($this->user->id, 'agent-1');
    $b = $this->cache->recordActivity($this->user->id, 'agent-2');
    $c = $this->cache->recordDispatch($this->user->id);

    expect($a)->not->toBeNull()->and($b)->not->toBeNull();
    expect($b['seq'])->toBeGreaterThan($a['seq'])
        ->and($c['seq'])->toBeGreaterThan($b['seq']);
});

test('keys are scoped per user, never bleeding into another user\'s count', function () {
    fakeRedis($this->store);
    $other = User::factory()->create();
    $this->cache->recordDispatch($this->user->id);

    $this->cache->recordActivity($other->id, 'agent-1');

    expect($this->cache->get($this->user->id))->toBe(1)
        ->and($this->cache->get($other->id))->toBe(1);
});

test('many returns each requested user\'s current count, zero for a user with nothing tracked', function () {
    fakeRedis($this->store);
    $other = User::factory()->create();
    $this->cache->recordDispatch($this->user->id);
    $this->cache->recordDispatch($this->user->id);

    $result = $this->cache->many([$this->user->id, $other->id]);

    expect($result)->toBe([$this->user->id => 2, $other->id => 0]);
});

test('sweepAllStale returns a user whose count dropped since the last broadcast', function () {
    fakeRedis($this->store);
    $this->cache->recordDispatch($this->user->id);
    $this->cache->recordDispatch($this->user->id);
    $this->cache->sweepAllStale(); // establishes the "last broadcast" baseline at 2

    unset($this->store[array_key_first($this->store)]); // simulate one key expiring

    $result = $this->cache->sweepAllStale();

    expect($result)->toHaveKey($this->user->id)
        ->and($result[$this->user->id]['count'])->toBe(1);
});

test('sweepAllStale returns nothing for a user whose count has not changed since the last broadcast', function () {
    fakeRedis($this->store);
    $this->cache->recordDispatch($this->user->id);
    $this->cache->sweepAllStale();

    $result = $this->cache->sweepAllStale();

    expect($result)->toBe([]);
});

test('sweepAllStale never revisits a user with no presence keys at all', function () {
    fakeRedis($this->store);
    $result = $this->cache->sweepAllStale();

    expect($result)->toBe([]);
});

test('every public method fails soft (returns a safe default, never throws) when Redis is unreachable', function () {
    Redis::shouldReceive('setex')->andThrow(new RedisException('connection refused'));
    Redis::shouldReceive('exists')->andThrow(new RedisException('connection refused'));
    Redis::shouldReceive('keys')->andThrow(new RedisException('connection refused'));
    Redis::shouldReceive('scan')->andThrow(new RedisException('connection refused'));

    expect($this->cache->recordDispatch($this->user->id))->toBe(['count' => 0, 'seq' => 0])
        ->and($this->cache->recordActivity($this->user->id, 'agent-1'))->toBeNull()
        ->and($this->cache->get($this->user->id))->toBe(0)
        ->and($this->cache->many([$this->user->id]))->toBe([$this->user->id => 0])
        ->and($this->cache->sweepAllStale())->toBe([]);
});
