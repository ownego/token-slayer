<?php

use App\Enums\FighterCharacter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('user factory creates a slack-linked user with a hashed hook token', function () {
    $user = User::factory()->create();

    expect($user->slack_user_id)->not->toBeNull()
        ->and($user->slack_handle)->not->toBeNull()
        ->and($user->avatar_url)->toStartWith('http')
        ->and($user->hook_token)->not->toBeNull() // hashed
        ->and(strlen($user->hook_token))->toBeGreaterThan(40);
});

test('displayHandle prefers slack_handle over display_name', function () {
    $user = User::factory()->create(['slack_handle' => 'alice.smith', 'display_name' => 'Alice']);
    expect($user->displayHandle())->toBe('alice.smith');
});

test('displayHandle falls back to display_name when slack_handle is null', function () {
    $user = User::factory()->create(['slack_handle' => null, 'display_name' => 'Alice']);
    expect($user->displayHandle())->toBe('Alice');
});

test('displayHandle falls back to name when display_name and slack_handle are null', function () {
    $user = User::factory()->create(['display_name' => null, 'slack_handle' => null, 'name' => 'Trung']);
    expect($user->displayHandle())->toBe('Trung');
});

test('displayHandle falls back to #id when display_name, slack_handle, and name are all empty', function () {
    $user = User::factory()->create(['display_name' => null, 'slack_handle' => null, 'name' => '']);
    expect($user->displayHandle())->toBe('#'.$user->id);
});

test('characterForBoss assigns a fighter deterministically from user and boss ids', function () {
    $user = User::factory()->create();
    $cases = FighterCharacter::cases();
    $expected = $cases[($user->id + 7) % count($cases)];

    expect($user->characterForBoss(7))->toBe($expected->value)
        ->and($user->characterForBoss(7))->toBe($expected->value);
});

test('characterForBoss rotates through all fifteen fighters across consecutive bosses', function () {
    $user = User::factory()->create();

    $characters = collect(range(0, 14))
        ->map(fn (int $bossId) => $user->characterForBoss($bossId))
        ->unique();

    expect($characters)->toHaveCount(15);
});
