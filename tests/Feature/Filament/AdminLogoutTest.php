<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('redirects to the battlefield after logging out of the admin panel', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('filament.admin.auth.logout'))
        ->assertRedirect(route('battlefield'));

    $this->assertGuest();
});
