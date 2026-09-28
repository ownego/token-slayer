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

test('spawns ThaNode once seven bosses in a row have gone without it', function () {
    foreach (range(51, 57) as $number) {
        Boss::factory()->defeated()->create(['number' => $number]);
    }

    $boss = $this->arena->spawnNext();

    expect($boss->number)->toBe(58)
        ->and($boss->name)->toBe('ThaNode');
});

test('does not spawn ThaNode before the arena has seen seven bosses', function () {
    foreach (range(1, 6) as $number) {
        Boss::factory()->defeated()->create(['number' => $number]);
    }

    expect($this->arena->spawnNext()->name)->not->toBe('ThaNode');
});

test('waits seven bosses after a ThaNode before the next one', function () {
    Boss::factory()->defeated()->thanode()->create(['number' => 10]);
    foreach (range(11, 16) as $number) {
        Boss::factory()->defeated()->create(['number' => $number]);
    }

    expect($this->arena->spawnNext()->name)->not->toBe('ThaNode');

    $this->arena->current()->update(['status' => 'defeated']);

    expect($this->arena->spawnNext()->name)->toBe('ThaNode');
});

test('spawning into a generic slot draws a name from the shared pool', function () {
    $pool = (new ReflectionClass(BossNameGenerator::class))
        ->getReflectionConstant('POOL')
        ->getValue();

    $boss = $this->arena->spawnNext();

    expect($boss->number)->toBe(1)
        ->and($boss->name)->toBeIn($pool);
});

test('the factory only makes a ThaNode when asked, whatever the number', function () {
    expect(Boss::factory()->make(['number' => 7])->name)->not->toBe('ThaNode')
        ->and(Boss::factory()->thanode()->make(['number' => 3])->name)->toBe('ThaNode');
});
