<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add what a player's minion pool needs beyond their custom minions: the keys
     * of the default looks they have switched off (null when they never touched
     * a tick, so every look is on), and a counter bumped on each change to the pool
     * so a client can tell a newer pool from an older one.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('minion_defaults_off')->nullable();
            $table->unsignedInteger('minion_pool_rev')->default(0);
        });
    }

    /**
     * Drop both columns.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['minion_defaults_off', 'minion_pool_rev']);
        });
    }
};
