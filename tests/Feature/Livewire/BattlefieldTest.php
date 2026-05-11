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
        ->assertSee('Boss #3')
        ->assertSee($fighter->slack_handle)
        ->assertSet('boss.id', $boss->id);
});
