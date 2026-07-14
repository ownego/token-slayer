<?php

use App\Enums\AccountStatus;
use App\Filament\Resources\Accounts\Pages\CreateAccount;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Accounts\RelationManagers\UsersRelationManager;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('blocks non-admins from the panel', function () {
    $this->actingAs(User::factory()->create(['is_admin' => false]))
        ->get('/admin')->assertForbidden();
});

it('lets an admin into the panel dashboard', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get('/admin')->assertOk();
});

it('lets an admin create and list accounts', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(CreateAccount::class)
        ->fillForm(['email' => 'new@ownego.com', 'plan' => 'max-20x'])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(Account::where('email', 'new@ownego.com')->exists())->toBeTrue();
});

it('rejects a duplicate account email on create', function () {
    Account::factory()->create(['email' => 'dupe@ownego.com']);
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(CreateAccount::class)
        ->fillForm(['email' => 'dupe@ownego.com', 'plan' => 'max-20x'])
        ->call('create')
        ->assertHasFormErrors(['email']);
});

it('lists accounts with member count and status badge', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $account = Account::factory()->needsReauth()->create(['email' => 'org@ownego.com']);
    $account->users()->attach(User::factory()->count(2)->create());

    Livewire::actingAs($admin)
        ->test(ListAccounts::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$account])
        ->assertTableColumnStateSet('users_count', 2, $account)
        ->assertTableColumnStateSet('status', AccountStatus::NeedsReauth, $account);
});

it('lets an admin set the organization uuid when editing an account', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $account = Account::factory()->create(['organization_uuid' => null]);

    Livewire::actingAs($admin)
        ->test(EditAccount::class, ['record' => $account->getRouteKey()])
        ->fillForm(['organization_uuid' => 'org-12345'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($account->refresh()->organization_uuid)->toBe('org-12345');
});

it('attaches and detaches members through the relation manager', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $account = Account::factory()->create();
    $user = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(UsersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->callTableAction('attach', data: ['recordId' => $user->id]);

    expect($account->users()->whereKey($user->id)->exists())->toBeTrue();

    Livewire::actingAs($admin)
        ->test(UsersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->callTableAction('detach', record: $user);

    expect($account->users()->whereKey($user->id)->exists())->toBeFalse();
});
