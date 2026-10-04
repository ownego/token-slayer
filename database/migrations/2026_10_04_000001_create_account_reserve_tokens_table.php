<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the per-account pool of Claude tokens an admin mints ahead of
     * time, so assigning or reissuing a device grant can skip the
     * authorize-URL / paste-code step. Raw tokens are stored encrypted (model
     * casts), like the grant's own `pending_claude_*` fields. Used, expired
     * and discarded rows are kept for audit, never deleted.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('account_reserve_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('token_uuid')->nullable();
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamp('access_expires_at');
            $table->timestamp('session_expires_at');
            $table->boolean('session_expires_at_estimated')->default(false);
            $table->timestamp('used_at')->nullable();
            $table->foreignId('used_grant_id')->nullable()->constrained('account_provisioned_grants')->nullOnDelete();
            $table->timestamp('discarded_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'used_at', 'discarded_at', 'session_expires_at'], 'reserve_tokens_available_idx');
        });
    }

    /**
     * Drop the reserve token pool.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('account_reserve_tokens');
    }
};
