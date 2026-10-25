<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add each event's list-price cost in micro-dollars, null when the turn
     * cannot be priced. The `(model, cost_micro_usd)` index serves the fill
     * that prices a model's unpriced events once its price appears; the
     * existing `(created_at, model)` index cannot, as it leads on time.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->bigInteger('cost_micro_usd')->nullable()->after('cache_read_input_tokens');
            $table->index(['model', 'cost_micro_usd']);
        });
    }

    /**
     * Drop the column and its index.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropIndex(['model', 'cost_micro_usd']);
            $table->dropColumn('cost_micro_usd');
        });
    }
};
