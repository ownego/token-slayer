<?php

use App\Enums\AccountPlan;
use App\Enums\AccountStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create `claude_credentials` and backfill one row per existing
     * `accounts` row from its current column values. Old columns stay on
     * `accounts` — this is Deploy 1 of 2; a follow-up migration drops them
     * separately after a production bake period.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('claude_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('organization_uuid')->nullable()->unique();
            $table->string('organization_type')->nullable();
            $table->string('rate_limit_tier')->nullable();
            $table->string('plan')->default(AccountPlan::Max20x->value);
            $table->text('oauth_access_token')->nullable();
            $table->text('oauth_refresh_token')->nullable();
            $table->timestampTz('oauth_expires_at')->nullable();
            $table->timestampTz('oauth_refresh_expires_at')->nullable();
            $table->string('status')->default(AccountStatus::Active->value);
            $table->timestampTz('last_probed_at')->nullable();
            $table->string('probe_error')->nullable();
            $table->string('account_uuid')->nullable()->unique();
            $table->timestamps();
        });

        DB::table('accounts')->orderBy('id')->each(function (object $row): void {
            DB::table('claude_credentials')->insert([
                'account_id' => $row->id,
                'organization_uuid' => $row->organization_uuid,
                'organization_type' => $row->organization_type,
                'rate_limit_tier' => $row->rate_limit_tier,
                'plan' => $row->plan,
                'oauth_access_token' => $row->oauth_access_token,
                'oauth_refresh_token' => $row->oauth_refresh_token,
                'oauth_expires_at' => $row->oauth_expires_at,
                'oauth_refresh_expires_at' => null,
                'status' => $row->status,
                'last_probed_at' => $row->last_probed_at,
                'probe_error' => $row->probe_error,
                'account_uuid' => $row->account_uuid,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Drop `claude_credentials`. Safe — the old `accounts` columns this
     * table was backfilled from are still present and untouched.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('claude_credentials');
    }
};
