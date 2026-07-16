<?php

use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\RelationManagers\EventsRelationManager as AccountEvents;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\RelationManagers\EventsRelationManager as UserEvents;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('hides both event tables from a role that lacks view_events', function () {
    Permission::firstOrCreate(['name' => 'view_events', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'ViewAny:User', 'guard_name' => 'web']);
    $role = Role::create(['name' => 'no-events', 'guard_name' => 'web']);
    $role->givePermissionTo('ViewAny:User');
    $user = User::factory()->create();
    $user->assignRole($role);
    $account = Account::factory()->create();

    $this->actingAs($user);

    expect(AccountEvents::canViewForRecord($account, EditAccount::class))->toBeFalse()
        ->and(UserEvents::canViewForRecord($user, ViewUser::class))->toBeFalse();
});

it('shows both event tables to a role granted view_events', function () {
    Permission::firstOrCreate(['name' => 'view_events', 'guard_name' => 'web']);
    $role = Role::create(['name' => 'can-events', 'guard_name' => 'web']);
    $role->givePermissionTo('view_events');
    $user = User::factory()->create();
    $user->assignRole($role);
    $account = Account::factory()->create();

    $this->actingAs($user);

    expect(AccountEvents::canViewForRecord($account, EditAccount::class))->toBeTrue()
        ->and(UserEvents::canViewForRecord($user, ViewUser::class))->toBeTrue();
});

it('still gates the user event tab to the view page even with view_events', function () {
    Permission::firstOrCreate(['name' => 'view_events', 'guard_name' => 'web']);
    $role = Role::create(['name' => 'can-events-2', 'guard_name' => 'web']);
    $role->givePermissionTo('view_events');
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user);

    // View-only restriction from Task 7 still holds on top of the permission.
    expect(UserEvents::canViewForRecord($user, EditUser::class))->toBeFalse();
});
