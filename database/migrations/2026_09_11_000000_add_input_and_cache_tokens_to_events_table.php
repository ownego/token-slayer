<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Deliberately never summed into `tokens` (which drives damage):
            // Claude Code caches almost all repeated context, so a single
            // short turn can carry a cache_read_input_tokens in the hundreds
            // of thousands against a few hundred output tokens -- these are
            // their own columns, for analytics only.
            $table->unsignedBigInteger('input_tokens')->default(0)->after('tokens');
            $table->unsignedBigInteger('cache_creation_input_tokens')->default(0)->after('input_tokens');
            $table->unsignedBigInteger('cache_read_input_tokens')->default(0)->after('cache_creation_input_tokens');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['input_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens']);
        });
    }
};
