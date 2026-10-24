<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Create the `View:ModelPrices` permission gating the read-only page of
     * model prices and models missing one. Not granted to any role here (an
     * admin grants it in Shield, to the default role for everyone); the
     * cached permission list is reset so it exists for the next check.
     *
     * @return void
     */
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'View:ModelPrices', 'guard_name' => 'web']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Remove the permission row. Role assignments referencing it are
     * cascaded away by the `role_has_permissions` foreign key.
     *
     * @return void
     */
    public function down(): void
    {
        Permission::where('name', 'View:ModelPrices')->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
