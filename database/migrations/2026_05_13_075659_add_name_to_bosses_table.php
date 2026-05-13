<?php

use App\Services\BossNameGenerator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bosses', function (Blueprint $table) {
            $table->string('name')->nullable()->after('number');
        });

        $generator = app(BossNameGenerator::class);
        $recent = [];

        DB::table('bosses')->orderBy('number')->get(['id'])->each(function ($row) use ($generator, &$recent) {
            $name = $generator->next($recent);
            $recent[] = $name;
            DB::table('bosses')->where('id', $row->id)->update(['name' => $name]);
        });

        Schema::table('bosses', function (Blueprint $table) {
            $table->string('name')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('bosses', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
