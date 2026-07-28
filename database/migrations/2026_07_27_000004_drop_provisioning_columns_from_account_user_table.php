<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the legacy per-user provisioning columns from `account_user` now
     * that they have been backfilled onto `devices` +
     * `account_provisioned_grants`. Only `status` (membership) remains.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('account_user', function (Blueprint $table): void {
            $table->dropColumn(['token_uuid', 'provisioned_at', 'claimed_at', 'revoked_at', 'deprovisioned_at']);
        });
    }

    /**
     * Re-add the five nullable provisioning columns.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('account_user', function (Blueprint $table): void {
            $table->string('token_uuid')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('deprovisioned_at')->nullable();
        });
    }
};
