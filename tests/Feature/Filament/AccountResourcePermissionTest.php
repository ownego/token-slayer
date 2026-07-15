<?php

use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('blocks a role without update_account from editing an account', function () {
    Permission::firstOrCreate(['name' => 'ViewAny:Account', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'Update:Account', 'guard_name' => 'web']);
    $role = Role::create(['name' => 'account_viewer', 'guard_name' => 'web']);
    $role->givePermissionTo('ViewAny:Account');

    $user = User::factory()->create();
    $user->assignRole('account_viewer');
    $account = Account::factory()->create();

    Livewire::actingAs($user)
        ->test(EditAccount::class, ['record' => $account->getRouteKey()])
        ->assertForbidden();
});

it('lets a role with update_account edit an account', function () {
    Permission::firstOrCreate(['name' => 'ViewAny:Account', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'Update:Account', 'guard_name' => 'web']);
    $role = Role::create(['name' => 'account_editor', 'guard_name' => 'web']);
    $role->givePermissionTo(['ViewAny:Account', 'Update:Account']);

    $user = User::factory()->create();
    $user->assignRole('account_editor');
    $account = Account::factory()->create();

    Livewire::actingAs($user)
        ->test(EditAccount::class, ['record' => $account->getRouteKey()])
        ->assertOk();
});
