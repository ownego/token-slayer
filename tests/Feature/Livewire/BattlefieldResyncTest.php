<?php

use App\Livewire\Battlefield;
use App\Models\Boss;
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
