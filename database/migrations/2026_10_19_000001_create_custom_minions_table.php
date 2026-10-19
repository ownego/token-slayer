<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A player's confirmed custom minions: up to a handful each, every one a built
     * pose sheet whose strips sit under `custom-minions/{user_id}/{version_hash}/`.
     * `body_px` is the measured height of the minion's idle frame, which sizes it
     * on the battlefield; `source` says whether it was uploaded or copied from the
     * player's fighter.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('custom_minions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('version_hash', 16);
            $table->json('animations');
            $table->unsignedSmallInteger('body_px');
            $table->string('source', 16);
            $table->timestamps();

            $table->unique(['user_id', 'version_hash']);
        });
    }

    /**
     * Drop the table.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('custom_minions');
    }
};
