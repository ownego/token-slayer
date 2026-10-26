<?php

use App\Models\CustomMinion;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Puts `users` back as it was before the pool column: no `minion_pool` (RefreshDatabase has run
 * every migration, so it is there).
 *
 * @param  bool  $withLegacyColumns  whether the two columns staging had (`minion_defaults_off`, `minion_pool_rev`) exist
 * @return void
 */
function usersBeforeMinionPool(bool $withLegacyColumns): void
{
    Schema::table('users', function (Blueprint $table): void {
        $table->dropColumn('minion_pool');
    });

    if (! $withLegacyColumns) {
        return;
    }

    Schema::table('users', function (Blueprint $table): void {
        $table->json('minion_defaults_off')->nullable();
        $table->unsignedInteger('minion_pool_rev')->default(0);
    });
}

/**
 * Runs the migration's `up()`.
 *
 * @return void
 */
function migrateToMinionPool(): void
{
    (require database_path('migrations/2026_10_25_000004_add_minion_pool_to_users_table.php'))->up();
}

/**
 * What `users.minion_pool` holds for a player, decoded.
 *
 * @param  User  $user  the player
 * @return array<string, mixed>|null
 */
function foldedPoolOf(User $user): ?array
{
    $raw = DB::table('users')->where('id', $user->id)->value('minion_pool');

    return $raw === null ? null : json_decode($raw, true);
}

it('folds the staging columns and the unticked minions into one column, keeping the revision', function () {
    usersBeforeMinionPool(withLegacyColumns: true);
    $player = User::factory()->create();
    DB::table('users')->where('id', $player->id)->update(['minion_defaults_off' => json_encode(['demon-a', 'smw-bowser']), 'minion_pool_rev' => 7]);
    $ticked = CustomMinion::factory()->for($player)->create(['slot' => 1, 'enabled' => true]);
    $unticked = CustomMinion::factory()->for($player)->create(['slot' => 3, 'enabled' => false]);
    $untouched = User::factory()->create();
    $revOnly = User::factory()->create();
    DB::table('users')->where('id', $revOnly->id)->update(['minion_pool_rev' => 3]);
    $onlyAMinion = User::factory()->create();
    $loneUnticked = CustomMinion::factory()->for($onlyAMinion)->create(['slot' => 2, 'enabled' => false]);
    DB::table('users')->where('id', $onlyAMinion->id)->update(['minion_pool_rev' => 1]);

    migrateToMinionPool();

    expect(foldedPoolOf($player))->toBe(['rev' => 7, 'defaults_off' => ['demon-a', 'smw-bowser'], 'slots_off' => [3]])
        ->and(foldedPoolOf($untouched))->toBeNull()
        ->and(foldedPoolOf($revOnly))->toBe(['rev' => 3, 'defaults_off' => [], 'slots_off' => []])
        ->and(foldedPoolOf($onlyAMinion))->toBe(['rev' => 1, 'defaults_off' => [], 'slots_off' => [2]])
        ->and(Schema::hasColumn('users', 'minion_defaults_off'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'minion_pool_rev'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'minion_pool'))->toBeTrue()
        ->and((bool) DB::table('custom_sprites')->where('id', $ticked->id)->value('enabled'))->toBeTrue()
        ->and((bool) DB::table('custom_sprites')->where('id', $unticked->id)->value('enabled'))->toBeTrue()
        ->and((bool) DB::table('custom_sprites')->where('id', $loneUnticked->id)->value('enabled'))->toBeTrue();
});

it('hands the folded pool to the model as it was chosen', function () {
    usersBeforeMinionPool(withLegacyColumns: true);
    $player = User::factory()->create();
    DB::table('users')->where('id', $player->id)->update(['minion_defaults_off' => json_encode(['demon-a']), 'minion_pool_rev' => 7]);
    $kept = CustomMinion::factory()->for($player)->create(['slot' => 1, 'enabled' => true]);
    CustomMinion::factory()->for($player)->create(['slot' => 2, 'enabled' => false]);

    migrateToMinionPool();

    $state = $player->fresh()->minionPoolState();
    expect($state['rev'])->toBe(7)
        ->and($state['defaults'])->not->toContain('demon-a')
        ->and($state['custom'])->toBe([$kept->fresh()->broadcastState()]);
});

it('only adds the column on a database that never had the staging columns', function () {
    usersBeforeMinionPool(withLegacyColumns: false);
    $player = User::factory()->create();
    $minion = CustomMinion::factory()->for($player)->create(['slot' => 1]);

    migrateToMinionPool();

    expect(Schema::hasColumn('users', 'minion_pool'))->toBeTrue()
        ->and(foldedPoolOf($player))->toBeNull()
        ->and($player->fresh()->minionPoolState()['rev'])->toBe(0)
        ->and((bool) DB::table('custom_sprites')->where('id', $minion->id)->value('enabled'))->toBeTrue();
});

it('drops the column on the way down', function () {
    expect(Schema::hasColumn('users', 'minion_pool'))->toBeTrue();

    (require database_path('migrations/2026_10_25_000004_add_minion_pool_to_users_table.php'))->down();

    expect(Schema::hasColumn('users', 'minion_pool'))->toBeFalse();
});
