<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the deadline of a specific claimed grant's OWN Claude session —
     * separate from `claude_credentials.oauth_refresh_expires_at`, which is
     * one shared field per account that the server's own refresher and every
     * device's self-report race to extend, so one healthy channel hides
     * every other device quietly running out. `session_expires_at_estimated`
     * distinguishes a real client-reported/server-exchanged deadline from a
     * backfilled guess for a grant whose secret was already cleared before
     * this column existed.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('account_provisioned_grants', function (Blueprint $table): void {
            $table->timestamp('session_expires_at')->nullable()->after('pending_codex_auth_json');
            $table->boolean('session_expires_at_estimated')->default(false)->after('session_expires_at');
        });
    }

    /**
     * Drop the session-expiry columns.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('account_provisioned_grants', function (Blueprint $table): void {
            $table->dropColumn(['session_expires_at', 'session_expires_at_estimated']);
        });
    }
};
