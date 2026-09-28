<?php

use App\Models\Boss;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a ?sheet= query no longer opens the fighter sheet on load', function () {
    $user = User::factory()->create();
    Boss::factory()->create(['status' => 'alive', 'number' => 1]);

    $this->actingAs($user)->get('/battlefield?sheet=profile')
        ->assertOk()
        ->assertDontSee('role="dialog"', false);
});

test('without ?sheet= the fighter sheet starts closed', function () {
    $user = User::factory()->create();
    Boss::factory()->create(['status' => 'alive', 'number' => 1]);

    $this->actingAs($user)->get('/battlefield')
        ->assertOk()
        ->assertDontSee('role="dialog"', false);
});
