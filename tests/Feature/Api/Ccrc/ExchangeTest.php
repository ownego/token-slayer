<?php

use App\Models\IdeAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exchanges a one-time token for an identity', function () {
    $user = User::factory()->create(['slack_user_id' => 'U01ABCDEF', 'slack_handle' => 'huy']);
    [$plain] = IdeAccessToken::issueOneTimeCcrc($user, 'STATE123', 120);

    $this->postJson('/api/ccrc/auth/exchange', ['token' => $plain, 'state' => 'STATE123'])
        ->assertOk()
        ->assertJson(['slackUserId' => 'U01ABCDEF', 'handle' => 'huy']);
});

it('issues no token at all — this is what sets it apart from the IDE flow', function () {
    $user = User::factory()->create(['slack_user_id' => 'U01ABCDEF']);
    [$plain] = IdeAccessToken::issueOneTimeCcrc($user, 'STATE123', 120);
    $before = IdeAccessToken::count();

    $this->postJson('/api/ccrc/auth/exchange', ['token' => $plain, 'state' => 'STATE123'])
        ->assertOk();

    expect(IdeAccessToken::count())->toBe($before);
    expect(IdeAccessToken::query()->where('kind', 'bearer')->count())->toBe(0);
});

it('rejects an IDE one-time token — the two flows mint different kinds', function () {
    $user = User::factory()->create(['slack_user_id' => 'U01ABCDEF']);
    [$plain] = IdeAccessToken::issueOneTime($user, 'STATE123', 120);

    $this->postJson('/api/ccrc/auth/exchange', ['token' => $plain, 'state' => 'STATE123'])
        ->assertStatus(410);
});

it('rejects a mismatched state', function () {
    $user = User::factory()->create(['slack_user_id' => 'U01ABCDEF']);
    [$plain] = IdeAccessToken::issueOneTimeCcrc($user, 'STATE123', 120);

    $this->postJson('/api/ccrc/auth/exchange', ['token' => $plain, 'state' => 'WRONG'])
        ->assertStatus(410);
});

it('token is single-use', function () {
    $user = User::factory()->create(['slack_user_id' => 'U01ABCDEF']);
    [$plain] = IdeAccessToken::issueOneTimeCcrc($user, 'STATE123', 120);

    $this->postJson('/api/ccrc/auth/exchange', ['token' => $plain, 'state' => 'STATE123'])->assertOk();
    $this->postJson('/api/ccrc/auth/exchange', ['token' => $plain, 'state' => 'STATE123'])->assertStatus(410);
});

it('rejects an expired token', function () {
    $user = User::factory()->create(['slack_user_id' => 'U01ABCDEF']);
    [$plain] = IdeAccessToken::issueOneTimeCcrc($user, 'STATE123', 120);

    $this->travel(121)->seconds();

    $this->postJson('/api/ccrc/auth/exchange', ['token' => $plain, 'state' => 'STATE123'])
        ->assertStatus(410);
});

it('rejects a user with no slack_user_id — a 200 with a #id-fallback handle would mislead the hub', function () {
    $user = User::factory()->create(['slack_user_id' => null]);
    [$plain] = IdeAccessToken::issueOneTimeCcrc($user, 'STATE123', 120);

    $this->postJson('/api/ccrc/auth/exchange', ['token' => $plain, 'state' => 'STATE123'])
        ->assertStatus(410)
        ->assertJson(['error' => 'token_invalid_or_expired']);
});

it('still returns a #id-fallback handle when slack_user_id is present but the name chain is blank', function () {
    // Contrast with the test above: rejection is scoped to a blank
    // slack_user_id specifically. A present slack_user_id with nothing else
    // to build a handle from is accepted, falling back to displayHandle()'s
    // own '#id' — that fallback is a separate, accepted case, not a bug.
    $user = User::factory()->create([
        'slack_user_id' => 'U01ABCDEF',
        'slack_handle' => '',
        'display_name' => '',
        'name' => '',
    ]);
    [$plain] = IdeAccessToken::issueOneTimeCcrc($user, 'STATE123', 120);

    $this->postJson('/api/ccrc/auth/exchange', ['token' => $plain, 'state' => 'STATE123'])
        ->assertOk()
        ->assertJson(['slackUserId' => 'U01ABCDEF', 'handle' => '#'.$user->id]);
});

it('rate-limits /api/ccrc/auth/exchange', function () {
    for ($i = 0; $i < 30; $i++) {
        $this->postJson('/api/ccrc/auth/exchange', ['token' => 'x', 'state' => 'y']);
    }

    $this->postJson('/api/ccrc/auth/exchange', ['token' => 'x', 'state' => 'y'])
        ->assertStatus(429);
});
