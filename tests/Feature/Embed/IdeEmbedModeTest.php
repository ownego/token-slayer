<?php

use App\Models\Boss;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Boss::factory()->create(['number' => 1, 'max_hp' => 1_000, 'current_hp' => 1_000]);
});

test('battlefield without embed renders nav and footer', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/battlefield')
        ->assertOk()
        ->assertDontSee('data-ide-embed="true"', false);
});

test('battlefield with embed=ide hides chrome and includes the bridge script', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/battlefield?embed=ide')->assertOk();

    expect($response->getContent())->toContain('data-ide-embed="true"');
    expect($response->getContent())->toContain('ide-bridge');
});
