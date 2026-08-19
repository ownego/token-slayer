<?php

use App\Models\User;
use App\Services\HookTokenRotator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('rotate overwrites the hash and returns the matching plaintext', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'old-token')]);
    $original = $user->hook_token;

    $plain = (new HookTokenRotator)->rotate($user);

    expect($user->fresh()->hook_token)
        ->toBe(hash('sha256', $plain))
        ->not->toBe($original);
});

test('rotate returns a 48-character plaintext token', function () {
    $plain = (new HookTokenRotator)->rotate(User::factory()->create());

    expect($plain)->toHaveLength(48);
});

test('two rotations for the same user never return the same plaintext', function () {
    $user = User::factory()->create();
    $rotator = new HookTokenRotator;

    expect($rotator->rotate($user))->not->toBe($rotator->rotate($user));
});
