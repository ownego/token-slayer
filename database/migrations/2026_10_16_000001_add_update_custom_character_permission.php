<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Create the rename permission and attach all fighter permissions to super admins.
     *
     * @return void
     */
    public function up(): void
    {
        $names = ['ViewAny:CustomCharacter', 'Update:CustomCharacter', 'Delete:CustomCharacter'];
        foreach ($names as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        Role::where('name', 'super_admin')->where('guard_name', 'web')->first()?->givePermissionTo($names);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Remove only the new permission; the earlier migration owns ViewAny and Delete.
     *
     * @return void
     */
    public function down(): void
    {
        Permission::where('name', 'Update:CustomCharacter')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
