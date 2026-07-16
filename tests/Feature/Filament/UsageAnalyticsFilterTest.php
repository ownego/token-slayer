<?php

use App\Filament\Pages\UsageAnalytics;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('offers the all-time range on the usage analytics filter', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(UsageAnalytics::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee('All time');
});
