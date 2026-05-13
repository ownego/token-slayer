<?php

use App\Models\Boss;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('history page lists defeated bosses with killing-blow user', function () {
    $killer = User::factory()->create(['slack_handle' => 'alice']);
    Boss::factory()->defeated()->create([
        'number' => 1,
        'name' => 'Smaug',
        'killing_blow_user_id' => $killer->id,
        'spawned_at' => now()->subHours(6),
        'defeated_at' => now()->subHours(1),
    ]);

    $this->get('/history')->assertOk()->assertSee('Smaug')->assertSee('alice');
});
