<?php

use App\Livewire\Battlefield;
use App\Models\Boss;
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
