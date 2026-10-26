<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Users read per chunk, in `id` order.
     *
     * @var int
     */
    private const int CHUNK = 200;

    /**
     * The two columns an earlier version of this change put on `users`.
     *
     * @var list<string>
     */
    private const array LEGACY_COLUMNS = ['minion_defaults_off', 'minion_pool_rev'];

    /**
     * Keep a player's whole minion selection in one place: `users.minion_pool`.
     *
     * The column is null while the player never touched a tick (revision 0, every look
     * ticked). Otherwise it holds `{"rev": int, "defaults_off": [key, ...], "slots_off": [slot, ...]}`:
     * the revision that orders one pool against another, the default looks left out of the
     * pool and the slots of the custom minions left out. A minion in a slot that is not listed
     * is in the pool.
     *
     * The first form of this change kept the same facts in three places (`users.minion_defaults_off`,
     * `users.minion_pool_rev` and `enabled = false` on the minion rows). Those exist only on
     * staging, so they are folded into the new column only where they exist: a database that
     * never had them just gets the column. Once folded, the two columns are dropped and every
     * minion row is `enabled` again.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('minion_pool')->nullable();
        });

        $legacy = array_values(array_filter(self::LEGACY_COLUMNS, fn (string $column): bool => Schema::hasColumn('users', $column)));

        if ($legacy !== []) {
            $this->foldLegacySelection();

            Schema::table('users', function (Blueprint $table) use ($legacy): void {
                $table->dropColumn($legacy);
            });
        }
    }

    /**
     * Drop the column. A selection that was folded into it is not unfolded: the older form
     * only ever existed on staging.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('minion_pool');
        });
    }

    /**
     * Copy what the legacy columns and the unticked minion rows say into `minion_pool`,
     * then mark every minion row `enabled`.
     *
     * @return void
     */
    private function foldLegacySelection(): void
    {
        $hasDefaultsOff = Schema::hasColumn('users', 'minion_defaults_off');
        $hasRev = Schema::hasColumn('users', 'minion_pool_rev');
        $slotsOff = $this->untickedSlots();

        $columns = ['id', ...($hasDefaultsOff ? ['minion_defaults_off'] : []), ...($hasRev ? ['minion_pool_rev'] : [])];

        DB::table('users')->select($columns)->chunkById(self::CHUNK, function (Collection $users) use ($slotsOff): void {
            foreach ($users as $user) {
                $defaultsOff = json_decode((string) ($user->minion_defaults_off ?? ''), true);
                $defaultsOff = is_array($defaultsOff) ? array_values($defaultsOff) : [];
                $rev = max(0, (int) ($user->minion_pool_rev ?? 0));
                $slots = $slotsOff[$user->id] ?? [];

                if ($rev === 0 && $defaultsOff === [] && $slots === []) {
                    continue;
                }

                DB::table('users')->where('id', $user->id)->update([
                    'minion_pool' => json_encode(['rev' => $rev, 'defaults_off' => $defaultsOff, 'slots_off' => $slots]),
                ]);
            }
        });

        DB::table('custom_sprites')->where('type', 'minion')->where('enabled', false)->update(['enabled' => true]);
    }

    /**
     * The slots each player had unticked, from the minion rows.
     *
     * @return array<int, list<int>> slots by user id, lowest first
     */
    private function untickedSlots(): array
    {
        return DB::table('custom_sprites')
            ->where('type', 'minion')
            ->where('enabled', false)
            ->whereNotNull('slot')
            ->orderBy('slot')
            ->get(['user_id', 'slot'])
            ->groupBy('user_id')
            ->map(fn (Collection $rows): array => $rows->pluck('slot')->map(fn (mixed $slot): int => (int) $slot)->unique()->values()->all())
            ->all();
    }
};
