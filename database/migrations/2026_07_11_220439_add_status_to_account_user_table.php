<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the membership status column. The DB default is 'tracked', so
     * existing rows (deliberate member adds) and any plain attach become
     * tracked; the ingest recorder and backfill write 'untracked' explicitly.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('account_user', function (Blueprint $table): void {
            $table->string('status')->default('tracked')->after('user_id');
            $table->index(['account_id', 'status']);
        });
    }

    /**
     * Drop the status column and its index.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('account_user', function (Blueprint $table): void {
            $table->dropIndex(['account_id', 'status']);
            $table->dropColumn('status');
        });
    }
};
