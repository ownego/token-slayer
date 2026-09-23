<?php

use App\Models\Boss;
use App\Services\BossArena;
use App\Services\BossNameGenerator;
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

test('spawning into the thanos slot names the boss ThaNode', function () {
    Boss::factory()->defeated()->create(['number' => 6]);

    $boss = $this->arena->spawnNext();

    expect($boss->number)->toBe(7)
        ->and($boss->name)->toBe('ThaNode');
});

test('spawning into a generic slot draws a name from the shared pool', function () {
    $pool = (new ReflectionClass(BossNameGenerator::class))
        ->getReflectionConstant('POOL')
        ->getValue();

    $boss = $this->arena->spawnNext();

    expect($boss->number)->toBe(1)
        ->and($boss->name)->toBeIn($pool);
});

test('the factory names a boss the way the arena would, so fixtures match production', function () {
    expect(Boss::factory()->make(['number' => 7])->name)->toBe('ThaNode')
        ->and(Boss::factory()->make(['number' => 15])->name)->toBe('ThaNode');
});
