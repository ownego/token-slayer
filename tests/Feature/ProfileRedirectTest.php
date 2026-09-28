<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('there is no /profile page any more: the profile lives in the battlefield\'s fighter sheet', function () {
    $this->actingAs(User::factory()->create())
        ->get('/profile')
        ->assertNotFound();
});
