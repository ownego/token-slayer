<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the count of a member's company turns in a row that arrived without
     * model or input/cache tokens, and the likely reason. The account card
     * shows a dot while the count is above zero; a complete turn resets it.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedInteger('incomplete_events')->default(0);
            $table->string('incomplete_reason', 32)->nullable();
        });
    }

    /**
     * Drop both columns.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['incomplete_events', 'incomplete_reason']);
        });
    }
};
