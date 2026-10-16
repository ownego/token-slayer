<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The confirmed versions of a custom fighter, newest last: what the player
     * can switch back to. `custom_characters` holds the one in use; each version
     * is a folder of strips on the disk under its hash.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('custom_character_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_character_id')->constrained()->cascadeOnDelete();
            $table->string('version_hash', 16);
            $table->json('animations');
            $table->timestamps();

            $table->unique(['custom_character_id', 'version_hash']);
        });
    }

    /**
     * Drop the table.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('custom_character_versions');
    }
};
