<?php

use App\Livewire\Battlefield;
use App\Models\Boss;
use App\Models\CustomMinion;
use App\Models\User;
use App\Services\FighterPositionCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Boss::factory()->create();
});

test('resync dispatches the moved positions of active fighters', function () {
    $fighter = User::factory()->create(['last_event_at' => now()->subMinute()]);
    app(FighterPositionCache::class)->put($fighter->id, 0.4, 0.6);

    Livewire::test(Battlefield::class)
        ->call('resync')
        ->assertDispatched('battlefield-resynced', fn ($name, $params) => $params['positions'] === [
            ['user_id' => $fighter->id, 'x' => 0.4, 'y' => 0.6],
        ]);
});

test('resync omits a fighter who has never moved', function () {
    User::factory()->create(['last_event_at' => now()->subMinute()]);

    Livewire::test(Battlefield::class)
        ->call('resync')
        ->assertDispatched('battlefield-resynced', fn ($name, $params) => $params['positions'] === []);
});

test('resync omits an idle fighter even if a stale cached position exists', function () {
    $idle = User::factory()->create(['last_event_at' => now()->subHour()]);
    app(FighterPositionCache::class)->put($idle->id, 0.4, 0.6);

    Livewire::test(Battlefield::class)
        ->call('resync')
        ->assertDispatched('battlefield-resynced', fn ($name, $params) => $params['positions'] === []);
});

test('a guest viewer can trigger a resync', function () {
    $fighter = User::factory()->create(['last_event_at' => now()->subMinute()]);
    app(FighterPositionCache::class)->put($fighter->id, 0.1, 0.2);

    Livewire::test(Battlefield::class)
        ->call('resync')
        ->assertDispatched('battlefield-resynced', fn ($name, $params) => $params['positions'] === [
            ['user_id' => $fighter->id, 'x' => 0.1, 'y' => 0.2],
        ]);
});

test('sends each fighter\'s minion pool with the positions', function () {
    $owner = CustomMinion::factory()->create()->user;
    $owner->forceFill(['last_event_at' => now()->subMinute(), 'minion_pool' => ['rev' => 4, 'defaults_off' => [], 'slots_off' => []]])->save();
    $plain = User::factory()->create(['last_event_at' => now()->subMinute()]);
    $idle = CustomMinion::factory()->create()->user;
    $idle->forceFill(['last_event_at' => now()->subHour()])->save();

    Livewire::test(Battlefield::class)
        ->call('resync')
        ->assertDispatched('battlefield-resynced', function ($name, $params) use ($owner, $plain, $idle) {
            $pools = $params['minionPools'];

            return collect(array_keys($pools))->sort()->values()->all() === collect([$owner->id, $plain->id])->sort()->values()->all()
                && ! array_key_exists($idle->id, $pools)
                && $pools[$owner->id] == $owner->fresh()->minionPoolState()
                && $pools[$owner->id]['rev'] === 4
                && count($pools[$owner->id]['custom']) === 1
                && $pools[$plain->id] == $plain->fresh()->minionPoolState()
                && $pools[$plain->id]['custom'] === [];
        });
});
