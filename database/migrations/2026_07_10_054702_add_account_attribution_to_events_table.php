<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->after('boss_id')
                ->constrained('accounts')->nullOnDelete();
            $table->string('account_email')->nullable()->after('account_id');
            $table->string('account_source')->nullable()->after('account_email');
            $table->index(['account_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'created_at']);
            $table->dropConstrainedForeignId('account_id');
            $table->dropColumn(['account_email', 'account_source']);
        });
    }
};
