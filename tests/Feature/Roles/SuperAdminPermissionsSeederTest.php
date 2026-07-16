<?php

use Database\Seeders\SuperAdminPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('assigns every permission to the super_admin role and is idempotent', function () {
    Permission::create(['name' => 'View:Account', 'guard_name' => 'web']);
    Permission::create(['name' => 'Update:Account', 'guard_name' => 'web']);
    // Migration 2026_07_15_100001 already inserts this permission; use
    // firstOrCreate so RefreshDatabase's fresh migrate doesn't collide.
    Permission::firstOrCreate(['name' => 'view_usage_analytics', 'guard_name' => 'web']);

    $this->seed(SuperAdminPermissionsSeeder::class);
    $this->seed(SuperAdminPermissionsSeeder::class); // idempotent

    $role = Role::where('name', 'super_admin')->where('guard_name', 'web')->sole();

    expect($role->permissions()->count())->toBe(3);
});
