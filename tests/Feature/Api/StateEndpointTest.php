<?php

use App\Models\Boss;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('state endpoint returns current boss, active fighters, and recent log', function () {
    Boss::factory()->create(['number' => 5, 'max_hp' => 5_000_000, 'current_hp' => 3_200_000]);
    $active = User::factory()->create(['last_event_at' => now()->subMinutes(5)]);
    $idle = User::factory()->create(['last_event_at' => now()->subHour()]);
    Event::factory()->for($active)->create(['tokens' => 1234, 'created_at' => now()->subMinute()]);

    $body = $this->getJson('/api/state')->assertOk()->json();

    expect($body['boss']['number'])->toBe(5)
        ->and($body['boss']['current_hp'])->toBe(3_200_000)
        ->and(collect($body['fighters'])->pluck('id')->all())->toContain($active->id)->not->toContain($idle->id)
        ->and($body['log'])->toHaveCount(1);
});

test('state endpoint assigns each fighter a character for the alive boss', function () {
    $boss = Boss::factory()->create();
    $user = User::factory()->create(['last_event_at' => now()]);

    $body = $this->getJson('/api/state')->assertOk()->json();

    $fighter = collect($body['fighters'])->firstWhere('id', $user->id);
    expect($fighter['character'])->toBe($user->characterForBoss($boss->id));
});

test('state endpoint includes display_name for each fighter', function () {
    Boss::factory()->create();
    $user = User::factory()->create(['last_event_at' => now(), 'display_name' => 'Alice']);

    $body = $this->getJson('/api/state')->assertOk()->json();

    $fighter = collect($body['fighters'])->firstWhere('id', $user->id);
    expect($fighter)->toHaveKey('display_name', 'Alice');
});
