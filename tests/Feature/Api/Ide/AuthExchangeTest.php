<?php

use App\Models\IdeAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('exchanges a one-time token for a bearer', function () {
    $user = User::factory()->create();
    [$plain] = IdeAccessToken::issueOneTime($user, 'state-x', 120);

    $response = $this->postJson('/api/ide/auth/exchange', [
        'token' => $plain,
        'state' => 'state-x',
    ])->assertOk()->assertJsonStructure(['bearer']);

    $bearer = $response->json('bearer');

    expect(IdeAccessToken::resolveBearer($bearer)?->id)->toBe($user->id);
});

test('one-time token is single-use', function () {
    $user = User::factory()->create();
    [$plain] = IdeAccessToken::issueOneTime($user, 'st', 120);

    $this->postJson('/api/ide/auth/exchange', ['token' => $plain, 'state' => 'st'])->assertOk();
    $this->postJson('/api/ide/auth/exchange', ['token' => $plain, 'state' => 'st'])->assertStatus(410);
});

test('rejects a CCRC one-time token — the two flows mint different kinds', function () {
    $user = User::factory()->create();
    [$plain] = IdeAccessToken::issueOneTimeCcrc($user, 'st', 120);

    $this->postJson('/api/ide/auth/exchange', ['token' => $plain, 'state' => 'st'])
        ->assertStatus(410);
});

test('rejects mismatched state', function () {
    $user = User::factory()->create();
    [$plain] = IdeAccessToken::issueOneTime($user, 'st', 120);

    $this->postJson('/api/ide/auth/exchange', ['token' => $plain, 'state' => 'wrong'])
        ->assertStatus(410);
});

test('rejects expired token', function () {
    $user = User::factory()->create();
    [$plain] = IdeAccessToken::issueOneTime($user, 'st', 1);

    $this->travel(2)->seconds();

    $this->postJson('/api/ide/auth/exchange', ['token' => $plain, 'state' => 'st'])
        ->assertStatus(410);
});

test('revoke invalidates a bearer', function () {
    $user = User::factory()->create();
    [$plain] = IdeAccessToken::issueBearer($user);

    $this->withHeader('Authorization', 'Bearer '.$plain)
        ->postJson('/api/ide/auth/revoke')
        ->assertNoContent();

    expect(IdeAccessToken::resolveBearer($plain))->toBeNull();
});

test('rate-limits /api/ide/auth/exchange', function () {
    for ($i = 0; $i < 30; $i++) {
        $this->postJson('/api/ide/auth/exchange', ['token' => 'x', 'state' => 'y']);
    }

    $this->postJson('/api/ide/auth/exchange', ['token' => 'x', 'state' => 'y'])
        ->assertStatus(429);
});
