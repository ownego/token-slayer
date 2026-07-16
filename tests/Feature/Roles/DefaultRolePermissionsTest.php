<?php

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Create a default role granting the given permissions, without materialising
 * it onto any user. Uses forceFill for `is_default` to dodge the guardable-
 * columns test-process landmine (KB §21).
 */
function makeDefaultRole(string $name, array $permissions = []): Role
{
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::create(['name' => $name, 'guard_name' => 'web']);
    $role->givePermissionTo($permissions);
    $role->forceFill(['is_default' => true])->save();

    return $role;
}

it('implicitly grants default-role permissions to every user without assigning the role', function () {
    makeDefaultRole('viewer', ['view_usage_analytics']);

    $user = User::factory()->create(); // no roles assigned

    expect($user->hasRole('viewer'))->toBeFalse()               // never materialised
        ->and($user->can('view_usage_analytics'))->toBeTrue();  // implicitly granted
});

it('does not implicitly grant a permission no default role holds', function () {
    Permission::firstOrCreate(['name' => 'view_events', 'guard_name' => 'web']);
    makeDefaultRole('viewer', ['view_usage_analytics']);

    $user = User::factory()->create();

    expect($user->can('view_events'))->toBeFalse();
});

it('does not implicitly grant anything when no role is flagged default', function () {
    Permission::firstOrCreate(['name' => 'view_usage_analytics', 'guard_name' => 'web']);
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
    $role->givePermissionTo('view_usage_analytics'); // NOT default

    $user = User::factory()->create();

    expect($user->can('view_usage_analytics'))->toBeFalse();
});

it('lets any user reach the admin panel while a default role exists, even with no roles', function () {
    makeDefaultRole('viewer', []);

    $user = User::factory()->create(); // no roles assigned

    expect($user->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();
});

it('blocks panel access for a user with no roles when no default role exists', function () {
    $user = User::factory()->create();

    expect($user->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
});
