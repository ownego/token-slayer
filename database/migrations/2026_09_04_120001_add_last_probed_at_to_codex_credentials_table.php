<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add `codex_credentials.last_probed_at`, mirroring
     * `claude_credentials.last_probed_at` — needed so
     * `Account::lastProbedAt()` can proxy to the Codex side once it becomes
     * provider-branched (see the CredentialsProvider interface work).
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('codex_credentials', function (Blueprint $table): void {
            $table->timestampTz('last_probed_at')->nullable()->after('status');
        });
    }

    /**
     * Drop `codex_credentials.last_probed_at`.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('codex_credentials', function (Blueprint $table): void {
            $table->dropColumn('last_probed_at');
        });
    }
};
