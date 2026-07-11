<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drops the unused `character` column added directly on staging by a
     * migration that was never committed to git. Fighter character selection
     * is derived deterministically via FighterCharacter::forUserAndBoss(),
     * not stored on the user. Idempotent so it is safe on any environment.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'character')) {
                $table->dropColumn('character');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('character')->nullable()->after('hook_token');
        });
    }
};
