<?php

use App\Events\FighterAgentCountChanged;
use App\Events\FighterIdled;
use App\Models\User;
use App\Services\SubagentCountCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->store = [];
    fakeRedis($this->store);
});

test('sweep marks users past idle window and broadcasts FighterIdled', function () {
    Event::fake([FighterIdled::class]);

    $stale = User::factory()->create(['last_event_at' => now()->subMinutes(45)]);
    $fresh = User::factory()->create(['last_event_at' => now()->subMinutes(5)]);

    $this->artisan('fighters:sweep-idle')->assertSuccessful();

    Event::assertDispatched(FighterIdled::class, fn ($e) => $e->user->id === $stale->id);
    Event::assertNotDispatched(FighterIdled::class, fn ($e) => $e->user->id === $fresh->id);
});

test('the same sweep also notices a subagent key that has expired and broadcasts the user\'s new count', function () {
    Event::fake([FighterAgentCountChanged::class]);
    $user = User::factory()->create(['last_event_at' => now()->subMinutes(5)]);
    app(SubagentCountCache::class)->recordDispatch($user->id);
    $this->artisan('fighters:sweep-idle'); // establishes the "last broadcast" baseline at 1
    Event::fake([FighterAgentCountChanged::class]);

    // Simulates Redis expiring the presence key itself — the permanent
    // known-users SET (no TTL, real Redis never expires it) must survive,
    // or the sweep has no way left to notice this user needs re-checking.
    foreach (array_keys($this->store) as $key) {
        if (str_starts_with($key, 'subagent:presence:')) {
            unset($this->store[$key]);
        }
    }
    $this->artisan('fighters:sweep-idle')->assertSuccessful();

    expect(app(SubagentCountCache::class)->get($user->id))->toBe(0);
    Event::assertDispatched(FighterAgentCountChanged::class, fn ($e) => $e->user->id === $user->id && $e->count === 0);
});

test('the same sweep does not re-broadcast a subagent count that has not changed since the last sweep', function () {
    $user = User::factory()->create(['last_event_at' => now()->subMinutes(5)]);
    app(SubagentCountCache::class)->recordDispatch($user->id);
    $this->artisan('fighters:sweep-idle'); // establishes the "last broadcast" baseline at 1
    Event::fake([FighterAgentCountChanged::class]);

    $this->artisan('fighters:sweep-idle')->assertSuccessful();

    Event::assertNotDispatched(FighterAgentCountChanged::class);
});

test('the same sweep does nothing when no subagent has ever been tracked', function () {
    Event::fake([FighterAgentCountChanged::class]);

    $this->artisan('fighters:sweep-idle')->assertSuccessful();

    Event::assertNotDispatched(FighterAgentCountChanged::class);
});
