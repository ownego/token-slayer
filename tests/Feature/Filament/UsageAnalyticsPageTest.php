<?php

use App\Filament\Pages\UsageAnalytics;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a non-admin cannot reach the usage analytics page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(UsageAnalytics::getUrl(panel: 'admin'))
        ->assertForbidden();
});

test('an admin can render the usage analytics page', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(UsageAnalytics::getUrl(panel: 'admin'))
        ->assertOk();
});
