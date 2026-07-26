<?php

use App\Enums\AccountPlan;
use App\Services\Accounts\PlanResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the two raw profile columns and re-point `plan` at the normalized
     * AccountPlan value. The pre-existing `plan` column held the raw
     * `organization_type`, so copy it into `organization_type`, then resolve
     * `plan` (tier unknown for historical rows → Max accounts land on the
     * generic `max` until the next profile sync fills the tier).
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->string('organization_type')->nullable()->after('plan');
            $table->string('rate_limit_tier')->nullable()->after('organization_type');
        });

        $resolver = new PlanResolver;

        DB::table('accounts')->orderBy('id')->each(function (object $row) use ($resolver): void {
            $rawOrgType = $row->plan;
            $plan = $resolver->resolve($rawOrgType, null);

            DB::table('accounts')->where('id', $row->id)->update([
                'organization_type' => $rawOrgType,
                'plan' => $plan->value,
            ]);
        });
    }

    /**
     * Restore `plan` to the raw organization_type and drop the added columns.
     *
     * @return void
     */
    public function down(): void
    {
        DB::table('accounts')->whereNotNull('organization_type')->each(function (object $row): void {
            DB::table('accounts')->where('id', $row->id)->update(['plan' => $row->organization_type]);
        });

        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn(['organization_type', 'rate_limit_tier']);
        });
    }
};
