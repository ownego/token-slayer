<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per provisioned OAuth grant = per (account × device). The raw
     * grant secret is NEVER stored here — it lives encrypted in the cache
     * with a 24 h TTL; these columns are the durable audit/lifecycle record.
     * The one-live-grant-per-(account, device) invariant is enforced in
     * the service layer (partial unique indexes are not portable to the
     * SQLite test driver), backed by a plain lookup index here.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('account_provisioned_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->string('token_uuid')->nullable();
            $table->timestamp('provisioned_at');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('deprovisioned_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'device_id']);
        });
    }

    /**
     * Drop the grants table.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::drop('account_provisioned_grants');
    }
};
