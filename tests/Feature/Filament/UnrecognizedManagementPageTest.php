<?php

use App\Filament\Pages\UnrecognizedAccounts;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders both tabs and lists an unattached user', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $unattached = User::factory()->create(['name' => 'Floating Dev', 'slack_handle' => null, 'display_name' => null]);

    Livewire::actingAs($admin)
        ->test(UnrecognizedAccounts::class)
        ->assertOk()
        ->set('activeTab', 'users')
        ->assertSee('Floating Dev');
});

it('exposes the connect action for an org with no account', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(UnrecognizedAccounts::class)
        ->assertActionExists('connectAccount');
});
