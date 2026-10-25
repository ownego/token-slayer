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

test('state endpoint carries each fighter\'s minion pool, with its rev, and no longer custom_minions', function () {
    Boss::factory()->create();
    $plain = User::factory()->create(['last_event_at' => now()->subMinute()]);
    $owner = CustomMinion::factory()->create()->user;
    CustomMinion::factory()->create(['user_id' => $owner->id, 'slot' => 2]);
    $owner->forceFill(['last_event_at' => now()->subMinute(), 'minion_pool_rev' => 4, 'minion_defaults_off' => ['demon-a']])->save();

    $fighters = collect($this->getJson('/api/state')->assertOk()->json('fighters'))->keyBy('id');
    $pool = $fighters[$owner->id]['minion_pool'];

    expect($pool)->toBe($owner->fresh()->minionPoolState())
        ->and($pool['rev'])->toBe(4)
        ->and($pool['defaults'])->not->toContain('demon-a')
        ->and($pool['custom'])->toHaveCount(2)
        ->and($pool['custom'][0])->not->toHaveKeys(['created_at', 'user_id', 'id'])
        ->and($fighters[$plain->id]['minion_pool'])->toBe($plain->fresh()->minionPoolState())
        ->and($fighters[$plain->id]['minion_pool']['custom'])->toBe([])
        ->and($fighters[$owner->id])->not->toHaveKey('custom_minions')
        ->and($fighters[$owner->id])->not->toHaveKeys(['minion_pool_rev', 'minion_defaults_off']);
});
