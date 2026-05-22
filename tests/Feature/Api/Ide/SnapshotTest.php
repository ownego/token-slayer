<?php

use App\Models\Boss;
use App\Models\Event;
use App\Models\IdeAccessToken;
use App\Models\User;
use App\Services\FighterChargingCache;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('returns null boss and zero damage when no boss is alive', function () {
    $user = User::factory()->create();
    [$plain] = IdeAccessToken::issueBearer($user);

    $this->withHeader('Authorization', 'Bearer '.$plain)
        ->getJson('/api/ide/snapshot')
        ->assertOk()
        ->assertJson([
            'boss' => null,
            'yourDamage' => 0,
            'charging' => null,
        ]);
});

test('returns the latest alive boss plus the user damage summed across that boss', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    [$plain] = IdeAccessToken::issueBearer($user);

    $boss = Boss::create([
        'number' => 7,
        'name' => 'Test Boss',
        'max_hp' => 1000,
        'current_hp' => 700,
        'status' => 'alive',
        'spawned_at' => now(),
    ]);

    Event::factory()->create(['user_id' => $user->id, 'boss_id' => $boss->id, 'tokens' => 120]);
    Event::factory()->create(['user_id' => $user->id, 'boss_id' => $boss->id, 'tokens' => 80]);
    Event::factory()->create(['user_id' => $other->id, 'boss_id' => $boss->id, 'tokens' => 50]);

    $this->withHeader('Authorization', 'Bearer '.$plain)
        ->getJson('/api/ide/snapshot')
        ->assertOk()
        ->assertJson([
            'boss' => [
                'id' => 7,
                'name' => 'Test Boss',
                'maxHp' => 1000,
                'currentHp' => 700,
            ],
            'yourDamage' => 200,
            'charging' => null,
        ]);
});

test('includes the user current charging activity when present', function () {
    $user = User::factory()->create();
    [$plain] = IdeAccessToken::issueBearer($user);

    app(FighterChargingCache::class)->put($user->id, 'Bash: npm install');

    $this->withHeader('Authorization', 'Bearer '.$plain)
        ->getJson('/api/ide/snapshot')
        ->assertOk()
        ->assertJsonPath('charging', 'Bash: npm install');
});

test('returns 401 without a bearer token', function () {
    $this->getJson('/api/ide/snapshot')->assertUnauthorized();
});
