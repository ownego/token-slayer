<?php

use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('lists users with their assigned roles', function () {
    $admin = User::factory()->admin()->create();
    $viewer = User::factory()->create(['name' => 'Row Viewer']);
    Role::create(['name' => 'account_viewer', 'guard_name' => 'web']);
    $viewer->assignRole('account_viewer');

    Livewire::actingAs($admin)
        ->test(ListUsers::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$admin, $viewer]);
});

it('lets an admin assign a role to a user', function () {
    $admin = User::factory()->admin()->create();
    $role = Role::create(['name' => 'account_viewer', 'guard_name' => 'web']);
    $user = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(EditUser::class, ['record' => $user->getRouteKey()])
        ->fillForm(['roles' => [$role->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->fresh()->hasRole('account_viewer'))->toBeTrue();
});

it('lets an admin remove a role from a user', function () {
    $admin = User::factory()->admin()->create();
    $role = Role::create(['name' => 'account_viewer', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);

    Livewire::actingAs($admin)
        ->test(EditUser::class, ['record' => $user->getRouteKey()])
        ->fillForm(['roles' => []])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->fresh()->hasRole('account_viewer'))->toBeFalse();
});
