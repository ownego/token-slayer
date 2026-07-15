<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    /**
     * Create the `view_usage_analytics` permission so roles can be granted
     * access to the standalone `/admin/usage` Livewire page. This route sits
     * outside the Filament panel and therefore never received a
     * Shield-generated permission of its own; without this row, the route's
     * `can:view_usage_analytics` gate would have nothing to check against.
     * `super_admin` does not need this permission attached explicitly —
     * Shield's `Gate::before` bypass already grants it everything.
     *
     * @return void
     */
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'view_usage_analytics', 'guard_name' => 'web']);
    }

    /**
     * Remove the `view_usage_analytics` permission row. Any role assignments
     * referencing it are cascaded away by the `model_has_permissions` and
     * `role_has_permissions` foreign keys.
     *
     * @return void
     */
    public function down(): void
    {
        Permission::where('name', 'view_usage_analytics')->where('guard_name', 'web')->delete();
    }
};
