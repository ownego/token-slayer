<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the `is_default` flag: roles marked default are auto-assigned to
     * every user (see App\Services\Roles\DefaultRoleAssigner).
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->boolean('is_default')->default(false)->after('guard_name');
        });
    }

    /**
     * Drop the `is_default` flag.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropColumn('is_default');
        });
    }
};
