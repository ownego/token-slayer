<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the `deprovisioned_at` marker: set when the CLI confirms it removed
     * an account's local slot, so the org stops appearing in the `remove` list.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('account_user', function (Blueprint $table): void {
            $table->timestamp('deprovisioned_at')->nullable()->after('revoked_at');
        });
    }

    /**
     * Drop the `deprovisioned_at` marker column.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('account_user', function (Blueprint $table): void {
            $table->dropColumn('deprovisioned_at');
        });
    }
};
