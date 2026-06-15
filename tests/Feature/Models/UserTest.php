<?php

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

test('displayHandle prefers display_name over slack_handle', function () {
    $user = User::factory()->create(['display_name' => 'Alice', 'slack_handle' => 'alice.smith']);
    expect($user->displayHandle())->toBe('Alice');
});

test('displayHandle falls back to slack_handle when display_name is null', function () {
    $user = User::factory()->create(['display_name' => null, 'slack_handle' => 'alice.smith']);
    expect($user->displayHandle())->toBe('alice.smith');
});

test('displayHandle falls back to #id when both display_name and slack_handle are null', function () {
    $user = User::factory()->create(['display_name' => null, 'slack_handle' => null]);
    expect($user->displayHandle())->toBe('#'.$user->id);
});

test('characterForBoss assigns a fighter deterministically from user and boss ids', function () {
    $user = User::factory()->create();
    $cases = App\Enums\FighterCharacter::cases();
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
