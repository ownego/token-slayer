<?php

use App\Models\Boss;
use App\Services\BossArena;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->arena = app(BossArena::class));

test('current returns the alive boss or spawns boss #1 on first call', function () {
    expect(Boss::count())->toBe(0);

    $boss = $this->arena->current();

    expect($boss->number)->toBe(1)
        ->and($boss->status)->toBe('alive')
        ->and($boss->max_hp)->toBe(config('game.base_hp'));
});

test('spawnNext creates the next-numbered boss with linear HP', function () {
    Boss::factory()->defeated()->create(['number' => 7]);

    $next = $this->arena->spawnNext();

    expect($next->number)->toBe(8)
        ->and($next->max_hp)->toBe(8 * config('game.base_hp'))
        ->and($next->current_hp)->toBe($next->max_hp);
});
