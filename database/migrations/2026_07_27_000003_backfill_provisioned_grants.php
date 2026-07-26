<?php

use App\Services\Provisioning\LegacyGrantBackfiller;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Convert every legacy `account_user` provisioning row into a `devices`
     * + `account_provisioned_grants` row via {@see LegacyGrantBackfiller}.
     * Runs before the next migration drops the legacy pivot columns.
     *
     * @return void
     */
    public function up(): void
    {
        $legacyRows = DB::table('account_user')
            ->whereNotNull('provisioned_at')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();

        app(LegacyGrantBackfiller::class)->backfill($legacyRows);
    }

    /**
     * Irreversible: the backfilled `devices` and `account_provisioned_grants`
     * rows are not deleted on rollback, since they may have accrued live
     * claims/revocations after the backfill ran. No-op.
     *
     * @return void
     */
    public function down(): void
    {
        // Intentionally irreversible — see class docblock.
    }
};
