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
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('name')->nullable()->after('email');
            $table->string('account_uuid')->nullable()->unique()->after('plan');
            $table->string('organization_uuid')->nullable()->after('account_uuid');
            $table->text('oauth_access_token')->nullable()->after('organization_uuid');
            $table->text('oauth_refresh_token')->nullable()->after('oauth_access_token');
            $table->timestampTz('oauth_expires_at')->nullable()->after('oauth_refresh_token');
            $table->string('status')->default('active')->after('oauth_expires_at');
            $table->timestampTz('last_probed_at')->nullable()->after('status');
            $table->string('probe_error')->nullable()->after('last_probed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn([
                'name', 'account_uuid', 'organization_uuid', 'oauth_access_token',
                'oauth_refresh_token', 'oauth_expires_at', 'status', 'last_probed_at', 'probe_error',
            ]);
        });
    }
};
