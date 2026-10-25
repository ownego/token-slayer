<?php

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Puts the database back as it was before `custom_sprites`: the five old tables, empty
 * and shaped exactly as their migrations made them. RefreshDatabase has run every
 * migration by now, so `custom_sprites` is there and the old tables are gone.
 *
 * @param  bool  $withMinions  whether the two minion tables (staging only) exist too
 * @return void
 */
function restoreLegacySpriteTables(bool $withMinions = true): void
{
    Schema::dropIfExists('custom_sprites');

    Schema::create('custom_characters', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
        $table->string('name', 32)->default('Custom');
        $table->string('version_hash', 16);
        $table->json('animations');
        $table->boolean('active')->default(true);
        $table->timestamps();
    });
    Schema::create('custom_character_versions', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('custom_character_id')->constrained()->cascadeOnDelete();
        $table->string('version_hash', 16);
        $table->json('animations');
        $table->timestamps();

        $table->unique(['custom_character_id', 'version_hash']);
    });
    Schema::create('custom_character_drafts', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
        $table->string('version_hash', 16);
        $table->json('animations');
        $table->timestamps();
    });

    if (! $withMinions) {
        return;
    }

    Schema::create('custom_minions', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->string('version_hash', 16);
        $table->json('animations');
        $table->unsignedSmallInteger('body_px');
        $table->string('source', 16);
        $table->timestamps();

        $table->unique(['user_id', 'version_hash']);
    });
    Schema::create('custom_minion_drafts', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
        $table->string('version_hash', 16);
        $table->json('animations');
        $table->unsignedSmallInteger('body_px');
        $table->timestamps();
    });
}

/**
 * The animation table every seeded row carries.
 *
 * @return string it as the JSON the old tables stored
 */
function legacyAnimations(): string
{
    return json_encode(['idle' => ['frames' => 6, 'rate' => 8], 'walk' => ['frames' => 8, 'rate' => 10]]);
}

/**
 * Seeds one fighter the way the old tables held it.
 *
 * @param  User  $user  the owner
 * @param  string  $inUse  the hash in `custom_characters`
 * @param  list<string>  $versionHashes  the rows of `custom_character_versions`, oldest first (the in-use hash among them, or not)
 * @param  bool  $active  whether the fighter is equipped
 * @return int the `custom_characters` id
 */
function seedLegacyFighter(User $user, string $inUse, array $versionHashes, bool $active = true): int
{
    $characterId = DB::table('custom_characters')->insertGetId([
        'user_id' => $user->id,
        'name' => 'Sir Pixel',
        'version_hash' => $inUse,
        'animations' => legacyAnimations(),
        'active' => $active,
        'created_at' => '2026-10-01 09:00:00',
        'updated_at' => '2026-10-20 18:30:00',
    ]);

    foreach ($versionHashes as $index => $hash) {
        DB::table('custom_character_versions')->insert([
            'custom_character_id' => $characterId,
            'version_hash' => $hash,
            'animations' => legacyAnimations(),
            'created_at' => sprintf('2026-10-%02d 10:00:00', $index + 2),
            'updated_at' => sprintf('2026-10-%02d 10:00:00', $index + 2),
        ]);
    }

    return $characterId;
}

/**
 * Runs the new migration's `up()`.
 *
 * @return void
 */
function migrateToCustomSprites(): void
{
    (require database_path('migrations/2026_10_25_000001_create_custom_sprites_table.php'))->up();
}

/**
 * A user's rows in `custom_sprites`, oldest id first.
 *
 * @param  User  $user  the owner
 * @param  string  $type  `fighter` or `minion`
 * @return Illuminate\Support\Collection<int, stdClass>
 */
function spriteRows(User $user, string $type = 'fighter'): Illuminate\Support\Collection
{
    return DB::table('custom_sprites')->where('user_id', $user->id)->where('type', $type)->orderBy('id')->get();
}

test('moves each fighter, its earlier versions and its draft into custom_sprites', function () {
    restoreLegacySpriteTables();
    $user = User::factory()->create();
    seedLegacyFighter($user, 'bbbbbbbbbbbb', ['aaaaaaaaaaaa', 'bbbbbbbbbbbb', 'cccccccccccc']);
    DB::table('custom_character_drafts')->insert(['user_id' => $user->id, 'version_hash' => 'dddddddddddd', 'animations' => legacyAnimations(), 'created_at' => '2026-10-24 08:00:00', 'updated_at' => '2026-10-24 08:00:00']);

    migrateToCustomSprites();

    $rows = spriteRows($user);
    $current = $rows->firstWhere('state', 'current');
    $draft = $rows->firstWhere('state', 'draft');
    expect($rows)->toHaveCount(4)
        ->and($rows->where('state', 'previous')->pluck('version_hash')->all())->toBe(['aaaaaaaaaaaa', 'cccccccccccc'])
        ->and($rows->whereIn('state', ['current', 'previous'])->pluck('version_hash')->all())->toBe(['aaaaaaaaaaaa', 'bbbbbbbbbbbb', 'cccccccccccc'])
        ->and($current->version_hash)->toBe('bbbbbbbbbbbb')
        ->and((bool) $current->enabled)->toBeTrue()
        ->and($current->slot)->toBe(1)
        ->and(json_decode($current->data, true))->toBe(['name' => 'Sir Pixel', 'animations' => json_decode(legacyAnimations(), true)])
        ->and($current->created_at)->toBe('2026-10-01 09:00:00')
        ->and($current->updated_at)->toBe('2026-10-20 18:30:00')
        ->and($rows->firstWhere('version_hash', 'aaaaaaaaaaaa')->created_at)->toBe('2026-10-02 10:00:00')
        ->and($draft->version_hash)->toBe('dddddddddddd')
        ->and($draft->slot)->toBeNull()
        ->and(json_decode($draft->data, true))->toBe(['animations' => json_decode(legacyAnimations(), true)]);
});

test('keeps a switched-off fighter switched off', function () {
    restoreLegacySpriteTables();
    $user = User::factory()->create();
    seedLegacyFighter($user, 'aaaaaaaaaaaa', ['aaaaaaaaaaaa'], active: false);

    migrateToCustomSprites();

    expect((bool) spriteRows($user)->sole()->enabled)->toBeFalse();
});

test('creates the current row when the in-use hash has no version row', function () {
    restoreLegacySpriteTables();
    $user = User::factory()->create();
    seedLegacyFighter($user, 'zzzzzzzzzzzz', ['aaaaaaaaaaaa']);
    $loner = User::factory()->create();
    seedLegacyFighter($loner, 'yyyyyyyyyyyy', []);

    migrateToCustomSprites();

    $rows = spriteRows($user);
    expect($rows->pluck('state', 'version_hash')->all())->toBe(['aaaaaaaaaaaa' => 'previous', 'zzzzzzzzzzzz' => 'current'])
        ->and(spriteRows($loner)->sole()->state)->toBe('current')
        ->and(spriteRows($loner)->sole()->version_hash)->toBe('yyyyyyyyyyyy');
});

test('does not duplicate the in-use version as a previous row', function () {
    restoreLegacySpriteTables();
    $user = User::factory()->create();
    seedLegacyFighter($user, 'aaaaaaaaaaaa', ['aaaaaaaaaaaa', 'bbbbbbbbbbbb']);

    migrateToCustomSprites();

    $rows = spriteRows($user);
    expect($rows->where('version_hash', 'aaaaaaaaaaaa'))->toHaveCount(1)
        ->and($rows->firstWhere('version_hash', 'aaaaaaaaaaaa')->state)->toBe('current')
        ->and($rows->where('state', 'current'))->toHaveCount(1)
        ->and($rows->where('state', 'previous')->pluck('version_hash')->all())->toBe(['bbbbbbbbbbbb']);
});

test('moves staging minions into numbered slots with their names', function () {
    restoreLegacySpriteTables();
    $user = User::factory()->create();
    seedLegacyFighter($user, 'aaaaaaaaaaaa', ['aaaaaaaaaaaa']);
    $noFighter = User::factory()->create();
    foreach ([[$user, 'm1m1m1m1m1m1', 'upload'], [$user, 'm2m2m2m2m2m2', 'fighter'], [$noFighter, 'm3m3m3m3m3m3', 'fighter']] as [$owner, $hash, $source]) {
        DB::table('custom_minions')->insert(['user_id' => $owner->id, 'version_hash' => $hash, 'animations' => legacyAnimations(), 'body_px' => 22, 'source' => $source, 'created_at' => '2026-10-20 12:00:00', 'updated_at' => '2026-10-20 12:00:00']);
    }
    DB::table('custom_minion_drafts')->insert(['user_id' => $user->id, 'version_hash' => 'm4m4m4m4m4m4', 'animations' => legacyAnimations(), 'body_px' => 30, 'created_at' => '2026-10-21 12:00:00', 'updated_at' => '2026-10-21 12:00:00']);

    migrateToCustomSprites();

    $rows = spriteRows($user, 'minion');
    $minions = $rows->where('state', 'current')->values();
    $draft = $rows->firstWhere('state', 'draft');
    expect($minions->pluck('slot')->all())->toBe([1, 2])
        ->and($minions->pluck('version_hash')->all())->toBe(['m1m1m1m1m1m1', 'm2m2m2m2m2m2'])
        ->and($minions->every(fn (stdClass $row): bool => (bool) $row->enabled))->toBeTrue()
        ->and($minions->map(fn (stdClass $row): ?string => json_decode($row->data, true)['name'])->all())->toBe(['Minion 1', 'Sir Pixel'])
        ->and(json_decode($minions[1]->data, true))->toMatchArray(['source' => 'fighter', 'body_px' => 22, 'animations' => json_decode(legacyAnimations(), true)])
        ->and($draft->slot)->toBeNull()
        ->and(json_decode($draft->data, true))->toMatchArray(['body_px' => 30, 'source' => 'upload', 'animations' => json_decode(legacyAnimations(), true)])
        ->and(json_decode(spriteRows($noFighter, 'minion')->sole()->data, true)['name'])->toBe('Minion 1')
        ->and(spriteRows($noFighter, 'minion')->sole()->slot)->toBe(1);
});

test('skips minion tables that do not exist', function () {
    restoreLegacySpriteTables(withMinions: false);
    $user = User::factory()->create();
    seedLegacyFighter($user, 'aaaaaaaaaaaa', ['aaaaaaaaaaaa']);

    migrateToCustomSprites();

    expect(spriteRows($user))->toHaveCount(1)
        ->and(DB::table('custom_sprites')->where('type', 'minion')->count())->toBe(0);
});

test('drops the five old tables', function () {
    restoreLegacySpriteTables();

    migrateToCustomSprites();

    foreach (['custom_characters', 'custom_character_versions', 'custom_character_drafts', 'custom_minions', 'custom_minion_drafts'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse("{$table} should be gone");
    }
    expect(Schema::hasTable('custom_sprites'))->toBeTrue();
});

test('restores the fighter tables on down', function () {
    restoreLegacySpriteTables();
    $user = User::factory()->create();
    seedLegacyFighter($user, 'bbbbbbbbbbbb', ['aaaaaaaaaaaa', 'bbbbbbbbbbbb', 'cccccccccccc'], active: false);
    DB::table('custom_character_drafts')->insert(['user_id' => $user->id, 'version_hash' => 'dddddddddddd', 'animations' => legacyAnimations(), 'created_at' => '2026-10-24 08:00:00', 'updated_at' => '2026-10-24 08:00:00']);
    $migration = require database_path('migrations/2026_10_25_000001_create_custom_sprites_table.php');
    $migration->up();

    $migration->down();

    $character = DB::table('custom_characters')->sole();
    expect(Schema::hasTable('custom_sprites'))->toBeFalse()
        ->and($character->user_id)->toBe($user->id)
        ->and($character->name)->toBe('Sir Pixel')
        ->and($character->version_hash)->toBe('bbbbbbbbbbbb')
        ->and((bool) $character->active)->toBeFalse()
        ->and($character->created_at)->toBe('2026-10-01 09:00:00')
        ->and(json_decode($character->animations, true))->toBe(json_decode(legacyAnimations(), true))
        ->and(DB::table('custom_character_versions')->where('custom_character_id', $character->id)->orderBy('id')->pluck('version_hash')->all())->toBe(['aaaaaaaaaaaa', 'bbbbbbbbbbbb', 'cccccccccccc'])
        ->and(DB::table('custom_character_drafts')->sole()->version_hash)->toBe('dddddddddddd');

    $migration->up();

    expect(spriteRows($user))->toHaveCount(4);
});
