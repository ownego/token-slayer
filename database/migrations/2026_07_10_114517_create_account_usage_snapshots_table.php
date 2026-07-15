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
        Schema::create('account_usage_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('util_5h')->nullable();
            $table->unsignedTinyInteger('util_7d')->nullable();
            $table->unsignedTinyInteger('util_7d_sonnet')->nullable();
            $table->unsignedTinyInteger('util_7d_oi')->nullable();
            $table->timestampTz('reset_5h_at')->nullable();
            $table->timestampTz('reset_7d_at')->nullable();
            $table->json('raw');
            $table->timestampTz('created_at');
            $table->index(['account_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_usage_snapshots');
    }
};
