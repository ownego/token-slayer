<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Create the `ViewAny:CustomMinion` and `Delete:CustomMinion` permissions gating
     * the admin list of players' custom minions: seeing it, and taking a minion
     * down. A player can always remove their own without either. They are separate
     * from the fighter's `*:CustomCharacter` permissions, so a role that may take
     * fighters down does not silently gain the same power over minions. Both are
     * granted to `super_admin` (an admin grants them to other roles in Shield); the
     * cached permission list is reset so they exist for the next check.
     *
     * @return void
     */
    public function up(): void
    {
        $names = ['ViewAny:CustomMinion', 'Delete:CustomMinion'];

        foreach ($names as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        Role::where('name', 'super_admin')->where('guard_name', 'web')->first()?->givePermissionTo($names);

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
        Permission::whereIn('name', ['ViewAny:CustomMinion', 'Delete:CustomMinion'])
            ->where('guard_name', 'web')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
