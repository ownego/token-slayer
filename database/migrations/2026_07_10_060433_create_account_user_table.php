<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the account_user pivot table for many-to-many membership between
     * accounts and users, then copies any existing users.account_id
     * single-assignment data into the pivot so both remain in sync until the
     * legacy column is dropped in a later migration.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('account_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique(['account_id', 'user_id']);
            $table->timestamps();
        });

        // Copy the legacy single-account assignments into the pivot.
        $now = now();
        DB::table('users')->whereNotNull('account_id')->orderBy('id')
            ->each(function (object $user) use ($now): void {
                DB::table('account_user')->insertOrIgnore([
                    'account_id' => $user->account_id,
                    'user_id' => $user->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    /**
     * Reverse the migrations.
     *
     * Drops the account_user pivot table, leaving users.account_id untouched.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('account_user');
    }
};
