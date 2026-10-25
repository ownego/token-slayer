<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the effective-dated list prices used to cost usage events. A
     * model gets a new row only when its price changes, so an event is priced
     * with the row in force on its own day. Prices are USD per million tokens.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('model_prices', function (Blueprint $table): void {
            $table->id();
            $table->string('model', 64);
            $table->string('provider', 16);
            $table->date('effective_from');
            $table->decimal('input_per_mtok', 12, 6);
            $table->decimal('output_per_mtok', 12, 6);
            $table->decimal('cache_read_per_mtok', 12, 6);
            $table->decimal('cache_write_5m_per_mtok', 12, 6);
            $table->decimal('cache_write_1h_per_mtok', 12, 6)->nullable();
            $table->char('source_sha256', 64)->nullable();
            $table->timestamps();

            $table->unique(['model', 'effective_from']);
        });
    }

    /**
     * Drop the price table.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('model_prices');
    }
};
