<?php

use App\Events\FighterAgentCountChanged;
use App\Models\SubagentDispatch;
use App\Models\User;
use App\Services\SubagentCountCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

test('sweep drops a stale subagent slot and broadcasts the user\'s new count', function () {
    Event::fake([FighterAgentCountChanged::class]);
    $user = User::factory()->create();
    app(SubagentCountCache::class)->recordDispatch($user->id);
    SubagentDispatch::where('user_id', $user->id)->update(['last_seen_at' => now()->subMinutes(10)]);

    $this->artisan('subagents:sweep-idle')->assertSuccessful();

    expect(app(SubagentCountCache::class)->get($user->id))->toBe(0);
    Event::assertDispatched(FighterAgentCountChanged::class, fn ($e) => $e->user->id === $user->id && $e->count === 0);
});

test('sweep leaves a subagent slot alone while it is still within the idle window', function () {
    Event::fake([FighterAgentCountChanged::class]);
    $user = User::factory()->create();
    app(SubagentCountCache::class)->recordDispatch($user->id);

    $this->artisan('subagents:sweep-idle')->assertSuccessful();

    expect(app(SubagentCountCache::class)->get($user->id))->toBe(1);
    Event::assertNotDispatched(FighterAgentCountChanged::class);
});

test('sweep drops only the stale slot, keeping a still-fresh sibling for the same user', function () {
    Event::fake([FighterAgentCountChanged::class]);
    $user = User::factory()->create();
    app(SubagentCountCache::class)->recordActivity($user->id, 'stale-agent');
    app(SubagentCountCache::class)->recordActivity($user->id, 'fresh-agent');
    SubagentDispatch::where('agent_id', 'stale-agent')->update(['last_seen_at' => now()->subMinutes(10)]);

    $this->artisan('subagents:sweep-idle')->assertSuccessful();

    expect(app(SubagentCountCache::class)->get($user->id))->toBe(1)
        ->and(SubagentDispatch::where('agent_id', 'fresh-agent')->exists())->toBeTrue()
        ->and(SubagentDispatch::where('agent_id', 'stale-agent')->exists())->toBeFalse();
    Event::assertDispatched(FighterAgentCountChanged::class, fn ($e) => $e->user->id === $user->id && $e->count === 1);
});

test('sweep drops a slot stale by 45 seconds, proving the idle window is tens of seconds, not minutes', function () {
    Event::fake([FighterAgentCountChanged::class]);
    $user = User::factory()->create();
    app(SubagentCountCache::class)->recordDispatch($user->id);
    SubagentDispatch::where('user_id', $user->id)->update(['last_seen_at' => now()->subSeconds(45)]);

    $this->artisan('subagents:sweep-idle')->assertSuccessful();

    expect(app(SubagentCountCache::class)->get($user->id))->toBe(0);
});

test('sweep does nothing when no subagent has gone stale', function () {
    Event::fake([FighterAgentCountChanged::class]);

    $this->artisan('subagents:sweep-idle')->assertSuccessful();

    Event::assertNotDispatched(FighterAgentCountChanged::class);
});
