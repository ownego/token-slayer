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
     * generic `max` until the next profile sync fills the tier). The column's
     * DB-level default is also repointed from the raw `'max-20x'` string
     * (not a valid `AccountPlan` backing value) to the enum's Max 20x case,
     * so any insert that omits `plan` still reads back cleanly through the
     * model cast.
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

        Schema::table('accounts', function (Blueprint $table): void {
            $table->string('plan')->default(AccountPlan::Max20x->value)->change();
        });
    }

    /**
     * Restore `plan`'s default to the raw `'max-20x'` string, restore every
     * row's `plan` to its raw `organization_type`, and drop the added
     * columns.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->string('plan')->default('max-20x')->change();
        });

        DB::table('accounts')->whereNotNull('organization_type')->orderBy('id')->each(function (object $row): void {
            DB::table('accounts')->where('id', $row->id)->update(['plan' => $row->organization_type]);
        });

        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn(['organization_type', 'rate_limit_tier']);
        });
    }
};
