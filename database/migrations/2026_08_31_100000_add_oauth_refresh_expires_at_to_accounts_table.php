<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the nullable refresh-token deadline column. Populated by
     * {@see \App\Services\AccountTokenRefresher::refresh()} (the server's
     * own refresh path) and by the client-reported `expiring` list on
     * `POST /api/provisioned/confirm` — see
     * {@see \App\Services\AccountProvisioningService::confirmSetup()}.
     * Not a secret (a deadline, not a credential) — not added to the
     * `Account` model's `#[Hidden]` attribute list or given an `encrypted`
     * cast, unlike the OAuth token columns.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->timestamp('oauth_refresh_expires_at')->nullable()->after('oauth_expires_at');
        });
    }

    /**
     * Drop the column.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn('oauth_refresh_expires_at');
        });
    }
};
