<?php

use App\Livewire\Battlefield;
use App\Models\Boss;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('battlefield component renders the current boss and active fighters', function () {
    $boss = Boss::factory()->create(['number' => 3, 'max_hp' => 3_000_000, 'current_hp' => 1_500_000]);
    $fighter = User::factory()->create(['last_event_at' => now()->subMinutes(2)]);
    User::factory()->create(['last_event_at' => now()->subHour()]); // idle

    Livewire::test(Battlefield::class)
        ->assertSeeHtml('&quot;number&quot;:3')
        ->assertSeeHtml('&quot;handle&quot;:&quot;'.$fighter->slack_handle.'&quot;')
        ->assertSet('boss.id', $boss->id);
});

test('battlefield spawns boss #1 when no alive boss exists', function () {
    expect(Boss::count())->toBe(0);

    Livewire::test(Battlefield::class)
        ->assertSeeHtml('&quot;number&quot;:1')
        ->assertSet('boss.number', 1);

    expect(Boss::where('status', 'alive')->count())->toBe(1);
});

test('each fighter is included in the battlefield state payload for projectile origin lookup', function () {
    $fighter = User::factory()->create(['last_event_at' => now()->subMinute()]);

    Livewire::test(Battlefield::class)
        ->assertSeeHtml('&quot;id&quot;:'.$fighter->id);
});

test('battlefield mount carries data-battlefield-state for projectile destination lookup', function () {
    Boss::factory()->create(['number' => 1, 'max_hp' => 1_000, 'current_hp' => 1_000]);

    Livewire::test(Battlefield::class)
        ->assertSeeHtml('data-battlefield-state');
});

test('battlefield shows a link back to the profile page', function () {
    Livewire::test(Battlefield::class)
        ->assertSeeHtml('href="'.route('profile').'"')
        ->assertSee('Profile');
});

test('battlefield seeds leaderboard with per-fighter damage for the current boss', function () {
    $previousBoss = Boss::factory()->defeated()->create(['number' => 6]);
    $boss = Boss::factory()->create(['number' => 7]);
    $alice = User::factory()->create(['slack_handle' => 'alice', 'last_event_at' => now()->subMinute()]);
    $bob = User::factory()->create(['slack_handle' => 'bob', 'last_event_at' => now()->subMinute()]);

    Event::factory()->create(['user_id' => $alice->id, 'boss_id' => $boss->id, 'tokens' => 1_200]);
    Event::factory()->create(['user_id' => $alice->id, 'boss_id' => $boss->id, 'tokens' => 800]);
    Event::factory()->create(['user_id' => $bob->id, 'boss_id' => $boss->id, 'tokens' => 500]);
    // Damage on a previous boss must not leak into the current leaderboard.
    Event::factory()->create(['user_id' => $alice->id, 'boss_id' => $previousBoss->id, 'tokens' => 9_999]);

    Livewire::test(Battlefield::class)
        ->assertSeeHtml('&quot;userId&quot;:'.$alice->id.',&quot;handle&quot;:&quot;alice&quot;,&quot;damage&quot;:2000')
        ->assertSeeHtml('&quot;userId&quot;:'.$bob->id.',&quot;handle&quot;:&quot;bob&quot;,&quot;damage&quot;:500');
});

test('battlefield leaderboard payload is empty when no damage logged for the current boss', function () {
    Boss::factory()->create();

    Livewire::test(Battlefield::class)
        ->assertSeeHtml('&quot;leaderboard&quot;:[]');
});
