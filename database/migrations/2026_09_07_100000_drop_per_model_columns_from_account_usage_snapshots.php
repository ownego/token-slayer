<?php

use App\Services\Accounts\UsageBuckets;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the two per-model utilization columns.
     *
     * They bolted two of Anthropic's usage buckets onto typed columns:
     * `util_7d_sonnet` for `seven_day_sonnet` and `util_7d_oi` for
     * `seven_day_opus`. Both stopped being written the moment those keys left
     * the API response — the latest stored snapshot has them null — and
     * nothing ever read them: outside the prober that filled them, the only
     * mention was their cast declaration.
     *
     * Nothing is lost. The full probe response is kept verbatim in `raw`, so
     * every bucket these columns duplicated is still there for every snapshot
     * ever taken, and {@see UsageBuckets} reads them
     * from it — including buckets no column was ever added for.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('account_usage_snapshots', function (Blueprint $table): void {
            $table->dropColumn(['util_7d_sonnet', 'util_7d_oi']);
        });
    }

    /**
     * Restore the columns, empty. The values are not restored because they
     * were never the source of truth: `raw` was.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('account_usage_snapshots', function (Blueprint $table): void {
            $table->unsignedTinyInteger('util_7d_sonnet')->nullable();
            $table->unsignedTinyInteger('util_7d_oi')->nullable();
        });
    }
};
