<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create one row per price feed: the ETag and hash of the last applied
     * file (so the next fetch can be conditional and every applied file can
     * be traced) and when it was last checked and last changed a price.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('price_feed_states', function (Blueprint $table): void {
            $table->id();
            $table->string('feed', 32)->unique();
            $table->string('etag')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('changed_at')->nullable();
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
        Schema::dropIfExists('price_feed_states');
    }
};
