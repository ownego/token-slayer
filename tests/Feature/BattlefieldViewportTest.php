<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the battlefield locks the page zoom so iOS never zooms the game in on a double tap or a focused field', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/battlefield')
        ->assertOk()
        ->assertSee('<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">', false);
});

test('other pages keep the default, zoomable viewport', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/guide')
        ->assertOk()
        ->assertSee('<meta name="viewport" content="width=device-width, initial-scale=1.0">', false);
});
