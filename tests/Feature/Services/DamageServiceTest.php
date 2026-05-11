<?php

use App\Models\Boss;
use App\Models\User;
use App\Services\DamageService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->damage = app(DamageService::class));

test('damage reduces current boss HP without killing when boss survives', function () {
    $boss = Boss::factory()->create(['number' => 1, 'max_hp' => 1_000_000, 'current_hp' => 1_000_000]);
    $user = User::factory()->create();

    $result = $this->damage->apply($user, tokens: 250_000);

    expect($result->killedBosses)->toBe([])
        ->and($result->boss->id)->toBe($boss->id)
        ->and($result->boss->current_hp)->toBe(750_000);
});

test('damage that exceeds HP kills the boss and carries overflow to the next', function () {
    Boss::factory()->create(['number' => 1, 'max_hp' => 100, 'current_hp' => 100]);
    $user = User::factory()->create();

    $result = $this->damage->apply($user, tokens: 350);

    expect($result->killedBosses)->toHaveCount(1)
        ->and($result->killedBosses[0]->number)->toBe(1)
        ->and($result->killedBosses[0]->status)->toBe('defeated')
        ->and($result->killedBosses[0]->killing_blow_user_id)->toBe($user->id)
        ->and($result->boss->number)->toBe(2)
        ->and($result->boss->current_hp)->toBe($result->boss->max_hp - 250);
});

test('zero-token damage is a no-op', function () {
    Boss::factory()->create(['number' => 1, 'max_hp' => 1_000, 'current_hp' => 1_000]);
    $user = User::factory()->create();

    $result = $this->damage->apply($user, tokens: 0);

    expect($result->killedBosses)->toBe([])
        ->and($result->boss->current_hp)->toBe(1_000);
});

test('damage chains across multiple bosses when payload exceeds several HP pools', function () {
    config()->set('game.base_hp', 10);
    $user = User::factory()->create();
    // No bosses exist; lockedCurrent() will spawn #1 (HP 10).
    // Damage = 35 should: kill #1 (HP 10), kill #2 (HP 20 = 2*10), dent #3 to 25 (3*10 - 5).

    $result = $this->damage->apply($user, tokens: 35);

    expect($result->killedBosses)->toHaveCount(2)
        ->and($result->killedBosses[0]->number)->toBe(1)
        ->and($result->killedBosses[0]->status)->toBe('defeated')
        ->and($result->killedBosses[1]->number)->toBe(2)
        ->and($result->killedBosses[1]->status)->toBe('defeated')
        ->and($result->boss->number)->toBe(3)
        ->and($result->boss->current_hp)->toBe(25);

    // Stricter: persisted state matches return value.
    expect(Boss::find($result->killedBosses[0]->id)->status)->toBe('defeated')
        ->and(Boss::find($result->killedBosses[1]->id)->status)->toBe('defeated');
});
