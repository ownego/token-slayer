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
        Schema::create('bosses', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('number')->unique();
            $table->unsignedBigInteger('max_hp');
            $table->bigInteger('current_hp');
            $table->string('status')->default('alive'); // alive | defeated
            $table->timestampTz('spawned_at');
            $table->timestampTz('defeated_at')->nullable();
            $table->foreignId('killing_blow_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bosses');
    }
};
