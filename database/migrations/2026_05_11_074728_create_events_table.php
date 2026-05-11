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
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boss_id')->nullable()->constrained('bosses')->nullOnDelete();
            $table->string('provider'); // claude-code | codex
            $table->string('event_type');
            $table->unsignedBigInteger('tokens')->nullable();
            $table->string('session_id')->nullable();
            $table->json('raw_payload');
            $table->timestampsTz();
            $table->index(['user_id', 'created_at']);
            $table->index(['boss_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
