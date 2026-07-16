<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Ensures the `super_admin` role exists and holds every permission in the
 * table, so the Shield role editor shows all boxes ticked (the Gate::before
 * bypass stays on as a safety net for not-yet-synced permissions). Idempotent.
 */
class SuperAdminPermissionsSeeder extends Seeder
{
    /**
     * Create/find the super_admin role and sync it to all permissions.
     *
     * @return void
     */
    public function run(): void
    {
        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $role->syncPermissions(Permission::where('guard_name', 'web')->get());
    }
}
