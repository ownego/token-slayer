<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A built-but-not-yet-confirmed fighter: what a player previews before it
     * goes live. One per user (a new build replaces it); its files sit under
     * `custom-characters/{user_id}/staged/{version_hash}/`, apart from confirmed ones.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('custom_character_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('version_hash', 16);
            $table->json('animations');
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
        Schema::dropIfExists('custom_character_drafts');
    }
};
