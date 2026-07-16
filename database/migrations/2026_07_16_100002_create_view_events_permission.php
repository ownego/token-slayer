<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    /**
     * Create the `view_events` permission. `Event` is not a Filament Resource
     * (it is surfaced only through the Users/Accounts `EventsRelationManager`
     * tabs), so Shield never generated a permission for it; without this row
     * the event tabs would be visible to anyone who can open the parent
     * record. Each `EventsRelationManager` gates its `canViewForRecord()` on
     * this permission. `super_admin` does not need it attached explicitly —
     * Shield's `Gate::before` bypass already grants it everything.
     *
     * @return void
     */
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'view_events', 'guard_name' => 'web']);
    }

    /**
     * Remove the `view_events` permission row. Any role assignments
     * referencing it are cascaded away by the `model_has_permissions` and
     * `role_has_permissions` foreign keys.
     *
     * @return void
     */
    public function down(): void
    {
        Permission::where('name', 'view_events')->where('guard_name', 'web')->delete();
    }
};
