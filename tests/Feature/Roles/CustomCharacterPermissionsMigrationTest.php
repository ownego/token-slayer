<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('migration grants the three custom fighter permissions to super admins and only removes Update on rollback', function () {
    $migration = require database_path('migrations/2026_10_16_000001_add_update_custom_character_permission.php');
    $migration->up();
    $migration->up();

    $names = ['ViewAny:CustomCharacter', 'Update:CustomCharacter', 'Delete:CustomCharacter'];
    expect(Permission::whereIn('name', $names)->count())->toBe(3);
    $role = Role::where('name', 'super_admin')->where('guard_name', 'web')->sole();
    foreach ($names as $name) {
        expect($role->hasPermissionTo($name))->toBeTrue();
    }

    $migration->down();
    expect(Permission::whereIn('name', $names)->pluck('name')->all())
        ->toContain('ViewAny:CustomCharacter', 'Delete:CustomCharacter')
        ->not->toContain('Update:CustomCharacter');
});
