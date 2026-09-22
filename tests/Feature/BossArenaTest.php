<?php

use App\Models\Boss;
use App\Services\BossArena;
use App\Services\BossNameGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('spawning into the thanos slot names the boss ThaNode', function () {
    Boss::factory()->defeated()->create(['number' => 6]);

    $boss = app(BossArena::class)->spawnNext();

    expect($boss->number)->toBe(7)
        ->and($boss->name)->toBe('ThaNode');
});

test('spawning into a generic slot draws a name from the shared pool', function () {
    $pool = (new ReflectionClass(BossNameGenerator::class))
        ->getReflectionConstant('POOL')
        ->getValue();

    $boss = app(BossArena::class)->spawnNext();

    expect($boss->number)->toBe(1)
        ->and($boss->name)->toBeIn($pool);
});
