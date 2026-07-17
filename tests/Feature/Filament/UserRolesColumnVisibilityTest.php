<?php

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Build a user holding exactly the given permissions via a fresh role.
 */
function userWithPermissions(string $roleName, array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::create(['name' => $roleName, 'guard_name' => 'web']);
    $role->givePermissionTo($permissions);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('hides the roles column from someone who can only list users', function () {
    $viewer = userWithPermissions('user-lister', ['ViewAny:User']);
    User::factory()->create(['name' => 'Subject']);

    Livewire::actingAs($viewer)
        ->test(ListUsers::class)
        ->assertOk()
        ->assertTableColumnHidden('roles.name');
});

it('shows the roles column to someone who can view roles', function () {
    $manager = userWithPermissions('role-viewer', ['ViewAny:User', 'ViewAny:Role']);
    User::factory()->create(['name' => 'Subject']);

    Livewire::actingAs($manager)
        ->test(ListUsers::class)
        ->assertOk()
        ->assertTableColumnVisible('roles.name');
});

it('shows the roles column to super_admin via the gate bypass', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(ListUsers::class)
        ->assertOk()
        ->assertTableColumnVisible('roles.name');
});
