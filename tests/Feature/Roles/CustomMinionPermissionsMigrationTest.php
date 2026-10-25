<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('migration grants the two custom minion permissions to super admins and removes both on rollback', function () {
    $migration = require database_path('migrations/2026_10_25_000003_create_custom_minion_permissions.php');
    $migration->up();
    $migration->up();

    $names = ['ViewAny:CustomMinion', 'Delete:CustomMinion'];
    expect(Permission::whereIn('name', $names)->count())->toBe(2);
    $role = Role::where('name', 'super_admin')->where('guard_name', 'web')->sole();
    foreach ($names as $name) {
        expect($role->hasPermissionTo($name))->toBeTrue();
    }

    $migration->down();
    expect(Permission::whereIn('name', $names)->count())->toBe(0);
});

test('migration leaves the custom fighter permissions alone on rollback', function () {
    $migration = require database_path('migrations/2026_10_25_000003_create_custom_minion_permissions.php');
    $migration->up();
    $migration->down();

    expect(Permission::whereIn('name', ['ViewAny:CustomCharacter', 'Update:CustomCharacter', 'Delete:CustomCharacter'])->count())->toBe(3);
});
