<?php

use App\Models\Boss;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('boss factory creates an alive boss with linear HP', function () {
    $boss = Boss::factory()->create(['number' => 3]);

    expect($boss->status)->toBe('alive')
        ->and($boss->max_hp)->toBe(3 * config('game.base_hp'))
        ->and($boss->current_hp)->toBe($boss->max_hp)
        ->and($boss->defeated_at)->toBeNull();
});

test('boss has a defeated state', function () {
    $boss = Boss::factory()->defeated()->create();

    expect($boss->status)->toBe('defeated')
        ->and($boss->defeated_at)->not->toBeNull()
        ->and($boss->current_hp)->toBe(0);
});
