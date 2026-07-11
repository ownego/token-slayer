<?php

use App\Events\FighterMoved;
use App\Livewire\Battlefield;
use App\Models\Boss;
use App\Models\User;
use App\Services\FighterPositionCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Boss::factory()->create();
    Cache::flush();
});

test('authenticated user can move their fighter to valid coordinates', function () {
    Event::fake([FighterMoved::class]);
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Battlefield::class)
        ->call('move', 0.5, 0.7);

    Event::assertDispatched(FighterMoved::class, fn ($e) => $e->user->id === $user->id
        && $e->x === 0.5
        && $e->y === 0.7
    );

    expect(app(FighterPositionCache::class)->get($user->id))->toBe(['x' => 0.5, 'y' => 0.7]);
});

test('guest cannot move a fighter', function () {
    Event::fake([FighterMoved::class]);

    Livewire::test(Battlefield::class)
        ->call('move', 0.5, 0.7);

    Event::assertNotDispatched(FighterMoved::class);
});

test('move is rejected when x is out of bounds', function () {
    Event::fake([FighterMoved::class]);
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Battlefield::class)
        ->call('move', 0.01, 0.7);

    Livewire::actingAs($user)
        ->test(Battlefield::class)
        ->call('move', 0.99, 0.7);

    Event::assertNotDispatched(FighterMoved::class);
});

test('move is rejected when y is out of bounds', function () {
    Event::fake([FighterMoved::class]);
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Battlefield::class)
        ->call('move', 0.5, 0.01);

    Livewire::actingAs($user)
        ->test(Battlefield::class)
        ->call('move', 0.5, 0.99);

    Event::assertNotDispatched(FighterMoved::class);
});

test('second move within 1 second is rate-limited', function () {
    Event::fake([FighterMoved::class]);
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Battlefield::class)
        ->call('move', 0.5, 0.7)
        ->call('move', 0.6, 0.8);

    Event::assertDispatchedTimes(FighterMoved::class, 1);
});
