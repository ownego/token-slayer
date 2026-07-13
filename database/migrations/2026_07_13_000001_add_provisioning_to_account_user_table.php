<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add per-user provisioning tracking columns to the account_user pivot.
     * The raw grant itself is NOT stored here — it lives in the cache
     * (encrypted, 24 h TTL); these columns are the durable audit/revoke record.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('account_user', function (Blueprint $table): void {
            $table->string('token_uuid')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
        });
    }

    /**
     * Drop the provisioning tracking columns.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('account_user', function (Blueprint $table): void {
            $table->dropColumn(['token_uuid', 'provisioned_at', 'claimed_at', 'revoked_at']);
        });
    }
};
