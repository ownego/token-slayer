<?php

use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('an admin can view an account detail page with its quota history', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create(['email' => 'seen@example.com']);
    AccountUsageSnapshot::factory()->for($account)->create(['util_7d' => 42, 'created_at' => now()->subHour()]);

    $this->actingAs($admin)
        ->get(ViewAccount::getUrl(['record' => $account->id], panel: 'admin'))
        ->assertOk()
        ->assertSee('seen@example.com');
});

test('a non-admin cannot view an account detail page', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create();

    $this->actingAs($user)
        ->get(ViewAccount::getUrl(['record' => $account->id], panel: 'admin'))
        ->assertForbidden();
});

test('the account detail page renders an edit header action', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();

    Livewire::actingAs($admin)
        ->test(ViewAccount::class, ['record' => $account->getKey()])
        ->assertActionExists('edit');
});
