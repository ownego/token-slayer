<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replace the global unique index on `accounts.email` with a composite
     * unique on `(provider, email)`. The original index predates the
     * `provider` column and assumed one account per email across the whole
     * table; a person legitimately reusing the same email for both a Claude
     * and a Codex account was rejected with a raw SQL integrity-constraint
     * error instead of being allowed.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropUnique(['email']);
            $table->unique(['provider', 'email']);
        });
    }

    /**
     * Revert to the original global unique index on `accounts.email`.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropUnique(['provider', 'email']);
            $table->unique(['email']);
        });
    }
};
