<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A user's own fighter, built from a pose sheet they uploaded.
     *
     * One row per user: uploading again replaces the character. Only the
     * result is kept, never the uploaded sheet -- the strips live on the
     * public disk under `custom-characters/{user_id}/{version_hash}/`, and
     * the hash in the path is what busts the CDN cache when they change.
     * `animations` records each strip's frame count and playback rate, which
     * the battlefield needs to build the fighter's Phaser animations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('custom_characters', function (Blueprint $table) {
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
        Schema::dropIfExists('custom_characters');
    }
};
