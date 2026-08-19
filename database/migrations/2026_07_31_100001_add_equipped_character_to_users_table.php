<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A user's explicitly chosen fighter character. Null means the user has
     * never equipped one, in which case FighterCharacter::forUserAndBoss()
     * still supplies the deterministic per-boss assignment.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('equipped_character')->nullable()->after('hook_token');
        });
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('equipped_character');
        });
    }
};
