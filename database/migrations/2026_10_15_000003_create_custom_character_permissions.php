<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Create the `ViewAny:CustomCharacter` and `Delete:CustomCharacter` permissions
     * gating the admin list of players' custom fighters: seeing it, and taking a
     * fighter down. A player can always delete their own without either. Neither is
     * granted to a role here (an admin does that in Shield); the cached permission
     * list is reset so they exist for the next check.
     *
     * @return void
     */
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'ViewAny:CustomCharacter', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'Delete:CustomCharacter', 'guard_name' => 'web']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Remove the permission rows. Role assignments referencing them are
     * cascaded away by the `role_has_permissions` foreign key.
     *
     * @return void
     */
    public function down(): void
    {
        Permission::whereIn('name', ['ViewAny:CustomCharacter', 'Delete:CustomCharacter'])
            ->where('guard_name', 'web')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
