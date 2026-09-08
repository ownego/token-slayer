<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Move the Pending grant's raw secret off the ephemeral, TTL-bound cache
     * onto the grant row itself — the cache's 24 h/7 d expiry has repeatedly
     * lost real, unclaimed grants outright, with no recovery path once it
     * fires. These columns hold the exact same `Crypt`-encrypted material the
     * cache used to, just durably: alive until the employee claims it (then
     * cleared) or an admin revokes the grant, never on a clock.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('account_provisioned_grants', function (Blueprint $table): void {
            $table->text('pending_claude_access_token')->nullable()->after('token_uuid');
            $table->text('pending_claude_refresh_token')->nullable()->after('pending_claude_access_token');
            $table->timestamp('pending_claude_expires_at')->nullable()->after('pending_claude_refresh_token');
            $table->text('pending_codex_auth_json')->nullable()->after('pending_claude_expires_at');
        });
    }

    /**
     * Drop the pending-secret columns.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('account_provisioned_grants', function (Blueprint $table): void {
            $table->dropColumn([
                'pending_claude_access_token',
                'pending_claude_refresh_token',
                'pending_claude_expires_at',
                'pending_codex_auth_json',
            ]);
        });
    }
};
