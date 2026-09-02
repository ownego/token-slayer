<?php

use App\Enums\AccountStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add `accounts.provider` (default 'claude', so every existing row is
     * correctly backfilled with no explicit loop needed) and create
     * `codex_credentials`, mirroring `claude_credentials`'s shape for the
     * Codex provider.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->string('provider')->default('claude')->after('name');
        });

        Schema::create('codex_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('chatgpt_account_id')->nullable()->unique();
            $table->string('chatgpt_user_id')->nullable();
            $table->string('plan_type')->nullable();
            $table->text('codex_access_token')->nullable();
            $table->text('codex_refresh_token')->nullable();
            $table->timestampTz('codex_expires_at')->nullable();
            $table->timestampTz('earliest_refresh_at')->nullable();
            $table->timestampTz('last_refreshed_at')->nullable();
            $table->string('status')->default(AccountStatus::Active->value);
            $table->string('probe_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Drop `codex_credentials` and `accounts.provider`.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('codex_credentials');

        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn('provider');
        });
    }
};
