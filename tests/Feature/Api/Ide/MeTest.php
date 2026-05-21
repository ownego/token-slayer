<?php

use App\Models\IdeAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('returns the authed user identity', function () {
    $user = User::factory()->create(['name' => 'Ada', 'slack_handle' => 'ada']);
    [$plain] = IdeAccessToken::issueBearer($user);

    $this->withHeader('Authorization', 'Bearer '.$plain)
        ->getJson('/api/ide/me')
        ->assertOk()
        ->assertJson([
            'user' => [
                'id' => $user->id,
                'name' => 'Ada',
                'handle' => 'ada',
            ],
        ])
        ->assertJsonStructure(['user' => ['id', 'name', 'handle', 'avatarUrl']]);
});

test('401 without a bearer', function () {
    $this->getJson('/api/ide/me')->assertUnauthorized();
});

test('401 with a revoked bearer', function () {
    $user = User::factory()->create();
    [$plain, $token] = IdeAccessToken::issueBearer($user);
    $token->revoke();

    $this->withHeader('Authorization', 'Bearer '.$plain)
        ->getJson('/api/ide/me')
        ->assertUnauthorized();
});
