<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Create the super_admin role, hand it to every user who currently has
     * is_admin = true, then drop the now-retired column. super_admin needs
     * no explicit permissions attached — filament-shield's Gate::before
     * bypass (config/filament-shield.php super_admin.enabled) grants it
     * everything.
     *
     * @return void
     */
    public function up(): void
    {
        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $adminIds = DB::table('users')->where('is_admin', true)->pluck('id');

        foreach ($adminIds as $userId) {
            DB::table('model_has_roles')->insertOrIgnore([
                'role_id' => $role->id,
                'model_type' => User::class,
                'model_id' => $userId,
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }

    /**
     * Restore the column and re-flag whoever held super_admin. Does not
     * remove the role itself (roles are shared, not owned by this migration).
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('email');
        });

        $role = Role::where('name', 'super_admin')->where('guard_name', 'web')->first();

        if ($role === null) {
            return;
        }

        $adminIds = DB::table('model_has_roles')
            ->where('role_id', $role->id)
            ->where('model_type', User::class)
            ->pluck('model_id');

        DB::table('users')->whereIn('id', $adminIds)->update(['is_admin' => true]);
    }
};
