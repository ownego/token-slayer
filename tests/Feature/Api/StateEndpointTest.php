<?php

use App\Models\Boss;
use App\Models\CustomCharacter;
use App\Models\CustomMinion;
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

test('state endpoint carries each fighter\'s custom character, null for one without', function () {
    Boss::factory()->create();
    $plain = User::factory()->create(['last_event_at' => now()->subMinute()]);
    $custom = CustomCharacter::factory()->create()->user;
    $custom->forceFill(['last_event_at' => now()->subMinute()])->save();

    $fighters = collect($this->getJson('/api/state')->assertOk()->json('fighters'))->keyBy('id');

    expect($fighters[$plain->id])->toHaveKey('custom_character', null)
        ->and($fighters[$custom->id]['custom_character'])->toBe($custom->customFighterState());
});

test('state endpoint carries each fighter\'s custom minions as their broadcast state, not the raw rows', function () {
    Boss::factory()->create();
    $plain = User::factory()->create(['last_event_at' => now()->subMinute()]);
    $owner = CustomMinion::factory()->create()->user;
    CustomMinion::factory()->create(['user_id' => $owner->id]);
    $owner->forceFill(['last_event_at' => now()->subMinute()])->save();

    $fighters = collect($this->getJson('/api/state')->assertOk()->json('fighters'))->keyBy('id');
    $minions = $fighters[$owner->id]['custom_minions'];

    expect($fighters[$plain->id])->toHaveKey('custom_minions', [])
        ->and($minions)->toBe($owner->fresh()->customMinionsState())
        ->and($minions)->toHaveCount(2)
        ->and($minions[0])->not->toHaveKeys(['created_at', 'user_id', 'id']);
});
