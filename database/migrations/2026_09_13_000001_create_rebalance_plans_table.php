<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A rebalance plan an admin has adopted, and how far through it they are.
     *
     * Recalculating produces a draft that lives nowhere: it is a search
     * result, and the next search may differ. Adopting one writes it here,
     * and from then on the moves are worked through against this row. That
     * matters because executing a plan takes a browser round trip to
     * Anthropic per move, so it spans reloads, sessions and sometimes people
     * — and a plan that evaporated with the page handed back a differently
     * shaped list every time, computed from a half-applied fleet, which is
     * the one state worse than where it started.
     *
     * Superseded plans are kept rather than overwritten: they are the record
     * of what was decided and how much of it actually happened.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('rebalance_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adopted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('range');
            $table->unsignedTinyInteger('extra_accounts')->default(0);
            $table->json('moves');
            $table->json('accounts');
            $table->json('summary');
            $table->json('capacity');
            $table->json('applied_indexes');
            $table->timestamps();
        });
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('rebalance_plans');
    }
};
