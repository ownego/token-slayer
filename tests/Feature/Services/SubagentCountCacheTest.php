<?php

use App\Models\SubagentDispatch;
use App\Models\User;
use App\Services\SubagentCountCache;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->cache = app(SubagentCountCache::class);
});

test('recordDispatch creates a pending slot and returns the new total', function () {
    $result = $this->cache->recordDispatch($this->user->id);

    expect($result['count'])->toBe(1)
        ->and(SubagentDispatch::where('user_id', $this->user->id)->whereNull('agent_id')->count())->toBe(1);
});

test('recordDispatch called repeatedly grows the pending count', function () {
    $this->cache->recordDispatch($this->user->id);
    $this->cache->recordDispatch($this->user->id);
    $result = $this->cache->recordDispatch($this->user->id);

    expect($result['count'])->toBe(3);
});

test('recordActivity with a fresh agent_id claims an existing pending slot and returns null, since the count didn\'t change', function () {
    // null is the "don't broadcast a no-op FighterAgentCountChanged" signal
    // — mirrors pruneStale's own established contract. Claiming a pending
    // slot re-labels an existing row; the total is unaffected either way.
    $this->cache->recordDispatch($this->user->id);

    $result = $this->cache->recordActivity($this->user->id, 'agent-1');

    expect($result)->toBeNull()
        ->and(SubagentDispatch::where('user_id', $this->user->id)->where('agent_id', 'agent-1')->exists())->toBeTrue()
        ->and(SubagentDispatch::where('user_id', $this->user->id)->whereNull('agent_id')->count())->toBe(0)
        ->and($this->cache->get($this->user->id))->toBe(1);
});

test('recordActivity for the same agent_id again only refreshes last_seen_at and returns null, never grows the count', function () {
    $this->cache->recordDispatch($this->user->id);
    $this->cache->recordActivity($this->user->id, 'agent-1');

    $before = SubagentDispatch::where('agent_id', 'agent-1')->first()->last_seen_at;
    $this->travel(30)->seconds();
    $result = $this->cache->recordActivity($this->user->id, 'agent-1');
    $after = SubagentDispatch::where('agent_id', 'agent-1')->first()->last_seen_at;

    expect($result)->toBeNull()
        ->and($after->isAfter($before))->toBeTrue()
        ->and($this->cache->get($this->user->id))->toBe(1);
});

test('recordActivity with no pending slot to claim creates a new one and returns the new total, since this is how a previously-idle-pruned subagent re-summons itself', function () {
    // No prior recordDispatch — simulates a subagent whose original pending
    // slot was already claimed and later pruned by the idle sweep, then it
    // unexpectedly sends another event. This is the one branch that
    // genuinely changes the count, so — unlike the two tests above — it
    // must return a real result the caller broadcasts.
    $result = $this->cache->recordActivity($this->user->id, 'agent-1');

    expect($result['count'])->toBe(1)
        ->and(SubagentDispatch::where('user_id', $this->user->id)->where('agent_id', 'agent-1')->exists())->toBeTrue();
});

test('recordActivity claims only one pending slot even when several are outstanding, still returning null', function () {
    $this->cache->recordDispatch($this->user->id);
    $this->cache->recordDispatch($this->user->id);

    $result = $this->cache->recordActivity($this->user->id, 'agent-1');

    expect($result)->toBeNull()
        ->and(SubagentDispatch::where('user_id', $this->user->id)->whereNull('agent_id')->count())->toBe(1)
        ->and($this->cache->get($this->user->id))->toBe(2);
});

test('get returns the current total without pruning stale rows itself', function () {
    $this->cache->recordDispatch($this->user->id);
    $this->cache->recordActivity($this->user->id, 'agent-1');
    SubagentDispatch::where('agent_id', 'agent-1')->update(['last_seen_at' => now()->subHours(1)]);

    expect($this->cache->get($this->user->id))->toBe(1);
});

test('recordDispatch and recordActivity each return a strictly increasing seq for the same user', function () {
    // Each call must genuinely change the count to get a non-null,
    // seq-bearing result. recordDispatch always does. recordActivity only
    // does via its "no pending slot, re-emerging agent" branch — a
    // never-before-seen agent_id with nothing pending to claim — so these
    // use distinct agent_ids and no recordDispatch runs first (that would
    // leave a pending slot for the first recordActivity to claim instead,
    // returning null).
    $a = $this->cache->recordActivity($this->user->id, 'agent-1');
    $b = $this->cache->recordActivity($this->user->id, 'agent-2');
    $c = $this->cache->recordDispatch($this->user->id);

    expect($a)->not->toBeNull()->and($b)->not->toBeNull();
    expect($b['seq'])->toBeGreaterThan($a['seq'])
        ->and($c['seq'])->toBeGreaterThan($b['seq']);
});

test('slots are scoped per user, never bleeding into another user\'s count', function () {
    $other = User::factory()->create();
    $this->cache->recordDispatch($this->user->id);

    $this->cache->recordActivity($other->id, 'agent-1');

    expect($this->cache->get($this->user->id))->toBe(1)
        ->and($this->cache->get($other->id))->toBe(1);
});

test('many returns each requested user\'s current count in one query, zero for a user with nothing tracked', function () {
    $other = User::factory()->create();
    $this->cache->recordDispatch($this->user->id);
    $this->cache->recordDispatch($this->user->id);

    $result = $this->cache->many([$this->user->id, $other->id]);

    expect($result)->toBe([$this->user->id => 2, $other->id => 0]);
});
