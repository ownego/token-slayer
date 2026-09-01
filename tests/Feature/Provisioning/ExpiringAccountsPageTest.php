<?php

use App\Filament\Pages\ExpiringAccounts;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders and lists an account within the warning window', function () {
    $admin = User::factory()->create();
    $admin->givePermissionTo('view_usage_analytics');
    $account = Account::factory()->create(['oauth_refresh_expires_at' => now()->addHours(6), 'email' => 'soon@x.com']);

    Livewire::actingAs($admin)
        ->test(ExpiringAccounts::class)
        ->assertSuccessful()
        ->assertSee('soon@x.com');
});

it('reports the correct navigation badge count', function () {
    Account::factory()->create(['oauth_refresh_expires_at' => now()->addHours(6)]);
    Account::factory()->create(['oauth_refresh_expires_at' => now()->addDays(10)]); // outside window

    expect(ExpiringAccounts::getNavigationBadge())->toBe('1');
});

it('reconnect updates the row-scoped account when the authorized identity matches', function () {
    fakeAnthropic();
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create([
        'email' => 'ongtung2212002@gmail.com', // matches fakeAnthropic()'s resolved identity
        'oauth_refresh_expires_at' => now()->addHours(6),
    ]);

    Livewire::actingAs($admin)
        ->test(ExpiringAccounts::class)
        ->mountAction('reconnect', arguments: ['account' => $account->id])
        ->setActionData(['code' => 'pasted-code'])
        ->callMountedAction();

    expect($account->refresh()->oauth_access_token)->toBe('sk-ant-oat01-REDACTED');
});

it('reconnect rejects a mismatched identity instead of silently updating the wrong account', function () {
    fakeAnthropic(); // resolves to ongtung2212002@gmail.com
    $admin = User::factory()->admin()->create();
    $wrongAccount = Account::factory()->create([
        'email' => 'someone-else@example.com', // does NOT match the authorized identity
        'oauth_access_token' => 'sk-ant-oat01-ORIGINAL',
        'oauth_refresh_expires_at' => now()->addHours(6),
    ]);

    Livewire::actingAs($admin)
        ->test(ExpiringAccounts::class)
        ->mountAction('reconnect', arguments: ['account' => $wrongAccount->id])
        ->setActionData(['code' => 'pasted-code'])
        ->callMountedAction();

    // The mismatch must be rejected -- the row's account token is untouched.
    expect($wrongAccount->refresh()->oauth_access_token)->toBe('sk-ant-oat01-ORIGINAL');
});
