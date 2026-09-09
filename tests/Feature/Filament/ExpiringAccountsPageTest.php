<?php

use App\Filament\Pages\ExpiringAccounts;
use App\Models\Account;
use App\Models\ClaudeCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('the page loads for an admin and lists an expiring account', function (): void {
    $admin = User::factory()->admin()->create();
    $account = Account::create(['email' => 'soon@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $account->id, 'oauth_refresh_expires_at' => now()->addDay()]);

    Livewire::actingAs($admin)->test(ExpiringAccounts::class)
        ->assertOk()
        ->assertSee('soon@example.com');
});

it('is forbidden for a user without the view_usage_analytics permission', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(ExpiringAccounts::class)->assertForbidden();
});
