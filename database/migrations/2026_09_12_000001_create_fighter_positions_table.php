<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Durable per-user battlefield position — the source of truth behind
     * FighterPositionCache's fast-read cache layer. Needed because
     * CACHE_STORE=database and `php artisan optimize:clear` (run by
     * deploy-staging.sh on every deploy) truncates Laravel's own `cache`
     * table, which is where every position previously lived exclusively.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('fighter_positions', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->float('x');
            $table->float('y');
            $table->timestamps();
        });
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('fighter_positions');
    }
};
