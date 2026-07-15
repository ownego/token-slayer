<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the legacy single-account foreign key on `users`. Membership now
     * lives exclusively in the `account_user` pivot (see
     * `create_account_user_table`, which already copied every existing
     * assignment across before this column disappears).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_id');
        });
    }

    /**
     * Restore the column for rollback. Note: dropping is lossy for any
     * `account_id` values written after the pivot copy ran (Task 5) — this
     * down() only recreates the column, it does not restore data.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->after('id')
                ->constrained('accounts')->nullOnDelete();
        });
    }
};
