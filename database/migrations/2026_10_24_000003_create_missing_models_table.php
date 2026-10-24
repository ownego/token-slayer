<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the list of models seen on complete events without a price. The
     * price sync asks the feed for them and, once priced, fills their events'
     * cost and removes the row.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('missing_models', function (Blueprint $table): void {
            $table->id();
            $table->string('model', 64)->unique();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();
        });
    }

    /**
     * Drop the table.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('missing_models');
    }
};
