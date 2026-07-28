<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per physical machine of a user. `device_id` is the client
     * fingerprint; `'default'` is the legacy sentinel (migrated / first-ever
     * provision of a zero-device user); NULL is an admin-opened placeholder
     * awaiting its first contact. NULLs don't collide in the unique index,
     * so multiple placeholders can coexist. `name` is an optional
     * admin-facing label (e.g. the backfilled legacy device is named
     * "Default").
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_id')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'device_id']);
        });
    }

    /**
     * Drop the devices table.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::drop('devices');
    }
};
