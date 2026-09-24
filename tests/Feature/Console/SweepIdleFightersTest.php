<?php

use App\Events\FighterAgentCountChanged;
use App\Events\FighterIdled;
use App\Models\SubagentDispatch;
use App\Models\User;
use App\Services\SubagentCountCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

test('sweep marks users past idle window and broadcasts FighterIdled', function () {
    Event::fake([FighterIdled::class]);

    $stale = User::factory()->create(['last_event_at' => now()->subMinutes(45)]);
    $fresh = User::factory()->create(['last_event_at' => now()->subMinutes(5)]);

    $this->artisan('fighters:sweep-idle')->assertSuccessful();

    Event::assertDispatched(FighterIdled::class, fn ($e) => $e->user->id === $stale->id);
    Event::assertNotDispatched(FighterIdled::class, fn ($e) => $e->user->id === $fresh->id);
});

test('the same sweep also drops a stale subagent slot and broadcasts the user\'s new count', function () {
    Event::fake([FighterAgentCountChanged::class]);
    $user = User::factory()->create(['last_event_at' => now()->subMinutes(5)]);
    app(SubagentCountCache::class)->recordDispatch($user->id);
    SubagentDispatch::where('user_id', $user->id)->update(['last_seen_at' => now()->subMinutes(10)]);

    $this->artisan('fighters:sweep-idle')->assertSuccessful();

    expect(app(SubagentCountCache::class)->get($user->id))->toBe(0);
    Event::assertDispatched(FighterAgentCountChanged::class, fn ($e) => $e->user->id === $user->id && $e->count === 0);
});

test('the same sweep leaves a subagent slot alone while it is still within its own (much shorter) idle window', function () {
    Event::fake([FighterAgentCountChanged::class]);
    $user = User::factory()->create(['last_event_at' => now()->subMinutes(5)]);
    app(SubagentCountCache::class)->recordDispatch($user->id);

    $this->artisan('fighters:sweep-idle')->assertSuccessful();

    expect(app(SubagentCountCache::class)->get($user->id))->toBe(1);
    Event::assertNotDispatched(FighterAgentCountChanged::class);
});

test('the same sweep drops only the stale subagent slot, keeping a still-fresh sibling for the same user', function () {
    Event::fake([FighterAgentCountChanged::class]);
    $user = User::factory()->create(['last_event_at' => now()->subMinutes(5)]);
    app(SubagentCountCache::class)->recordActivity($user->id, 'stale-agent');
    app(SubagentCountCache::class)->recordActivity($user->id, 'fresh-agent');
    SubagentDispatch::where('agent_id', 'stale-agent')->update(['last_seen_at' => now()->subMinutes(10)]);

    $this->artisan('fighters:sweep-idle')->assertSuccessful();

    expect(app(SubagentCountCache::class)->get($user->id))->toBe(1)
        ->and(SubagentDispatch::where('agent_id', 'fresh-agent')->exists())->toBeTrue()
        ->and(SubagentDispatch::where('agent_id', 'stale-agent')->exists())->toBeFalse();
    Event::assertDispatched(FighterAgentCountChanged::class, fn ($e) => $e->user->id === $user->id && $e->count === 1);
});

test('the same sweep drops a subagent slot stale by 45 seconds, proving its idle window is tens of seconds, not minutes', function () {
    Event::fake([FighterAgentCountChanged::class]);
    $user = User::factory()->create(['last_event_at' => now()->subMinutes(5)]);
    app(SubagentCountCache::class)->recordDispatch($user->id);
    SubagentDispatch::where('user_id', $user->id)->update(['last_seen_at' => now()->subSeconds(45)]);

    $this->artisan('fighters:sweep-idle')->assertSuccessful();

    expect(app(SubagentCountCache::class)->get($user->id))->toBe(0);
});

test('the same sweep does nothing when no subagent has gone stale', function () {
    Event::fake([FighterAgentCountChanged::class]);

    $this->artisan('fighters:sweep-idle')->assertSuccessful();

    Event::assertNotDispatched(FighterAgentCountChanged::class);
});
