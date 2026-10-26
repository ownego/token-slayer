<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rows copied per chunk, in `id` order.
     *
     * @var int
     */
    private const int CHUNK = 200;

    /**
     * The five tables this one replaces, children first so their foreign keys never block a drop.
     *
     * @var list<string>
     */
    private const array LEGACY_TABLES = [
        'custom_character_versions',
        'custom_character_drafts',
        'custom_characters',
        'custom_minion_drafts',
        'custom_minions',
    ];

    /**
     * One table for every version of a custom sprite, fighter or minion.
     *
     * A row is one version of one sprite. `type`, `state`, `slot` and `enabled` are
     * plain columns so the database can enforce the rules on them with conditional
     * unique indexes; everything else (`name`, `animations`, and a minion's
     * `body_px` and `source`) sits in `data`. Fighters and minions that lived in the
     * five older tables are copied across, oldest first so history trimming still
     * drops the oldest version. The minion tables only ever existed on staging, so
     * they are copied only where they exist. Files on the disk are not touched.
     *
     * What `enabled` means depends on the row's `type` and `state`: on a fighter
     * `current` row it is whether the fighter is equipped; on a fighter `previous` or
     * `draft` row it is always true and never read; on a minion row (`current` or
     * `draft`) it is also always true and never read, because which minions are ticked
     * into the player's pool is stored on `users.minion_pool`, not on the row.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('custom_sprites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('state', 16);
            $table->unsignedSmallInteger('slot')->nullable();
            $table->string('version_hash', 16);
            $table->boolean('enabled')->default(true);
            $table->json('data');
            $table->timestamps();

            $table->index(['user_id', 'type', 'state'], 'custom_sprites_user_type_state_index');
        });

        DB::statement("CREATE UNIQUE INDEX custom_sprites_current_slot_unique ON custom_sprites (user_id, type, slot) WHERE state = 'current'");
        DB::statement("CREATE UNIQUE INDEX custom_sprites_draft_unique ON custom_sprites (user_id, type) WHERE state = 'draft'");
        DB::statement("CREATE UNIQUE INDEX custom_sprites_saved_hash_unique ON custom_sprites (user_id, type, version_hash) WHERE state <> 'draft'");

        $this->copyFighters();
        $this->copyFighterDrafts();
        $this->copyMinions();

        foreach (self::LEGACY_TABLES as $legacy) {
            Schema::dropIfExists($legacy);
        }
    }

    /**
     * Put the three fighter tables back and copy the fighter rows into them. Minion
     * rows are dropped: they were never shipped.
     *
     * @return void
     */
    public function down(): void
    {
        $this->createFighterTables();
        $this->restoreFighters();
        $this->restoreFighterDrafts();

        Schema::dropIfExists('custom_sprites');
    }

    /**
     * Copy each fighter, and the versions it can switch back to, into `custom_sprites`.
     *
     * @return void
     */
    private function copyFighters(): void
    {
        DB::table('custom_characters')->chunkById(self::CHUNK, function (Collection $characters): void {
            $versions = DB::table('custom_character_versions')
                ->whereIn('custom_character_id', $characters->pluck('id')->all())
                ->orderBy('id')
                ->get()
                ->groupBy('custom_character_id');
            $rows = [];

            foreach ($characters as $character) {
                array_push($rows, ...$this->fighterRows($character, $versions->get($character->id, new Collection)));
            }

            DB::table('custom_sprites')->insert($rows);
        });
    }

    /**
     * The rows one fighter becomes, in the order its versions were made. The version
     * in use becomes the `current` row in its own place in that order; every other
     * version is `previous`. A fighter whose in-use hash has no version row (old
     * data from before versions existed) gets its `current` row last.
     *
     * @param  object  $character  the `custom_characters` row
     * @param  Collection<int, object>  $versions  its `custom_character_versions` rows, oldest first
     * @return list<array<string, mixed>>
     */
    private function fighterRows(object $character, Collection $versions): array
    {
        $rows = [];
        $currentAdded = false;

        foreach ($versions as $version) {
            if ($version->version_hash === $character->version_hash) {
                $rows[] = $this->currentFighterRow($character);
                $currentAdded = true;

                continue;
            }

            $rows[] = $this->row($character->user_id, 'fighter', 'previous', 1, $version->version_hash, true, [
                'animations' => json_decode($version->animations, true),
            ], $version->created_at, $version->updated_at);
        }

        if (! $currentAdded) {
            $rows[] = $this->currentFighterRow($character);
        }

        return $rows;
    }

    /**
     * The `current` row of a fighter: it keeps the name, whether it is equipped, and the
     * fighter's own timestamps.
     *
     * @param  object  $character  the `custom_characters` row
     * @return array<string, mixed>
     */
    private function currentFighterRow(object $character): array
    {
        return $this->row($character->user_id, 'fighter', 'current', 1, $character->version_hash, (bool) $character->active, [
            'name' => $character->name,
            'animations' => json_decode($character->animations, true),
        ], $character->created_at, $character->updated_at);
    }

    /**
     * Copy each fighter draft into `custom_sprites`.
     *
     * @return void
     */
    private function copyFighterDrafts(): void
    {
        DB::table('custom_character_drafts')->chunkById(self::CHUNK, function (Collection $drafts): void {
            DB::table('custom_sprites')->insert($drafts->map(fn (object $draft): array => $this->row(
                $draft->user_id, 'fighter', 'draft', null, $draft->version_hash, true,
                ['animations' => json_decode($draft->animations, true)],
                $draft->created_at, $draft->updated_at,
            ))->all());
        });
    }

    /**
     * Copy the minions and minion drafts into `custom_sprites`, where the tables exist.
     * Each player's minions take slots 1, 2, 3 in the order they were added, all ticked.
     * An uploaded minion is named after its slot; one copied from the fighter takes the
     * fighter's name, or the slot's name when the player has no fighter any more.
     *
     * @return void
     */
    private function copyMinions(): void
    {
        if (Schema::hasTable('custom_minions')) {
            $fighterNames = Schema::hasTable('custom_characters') ? DB::table('custom_characters')->pluck('name', 'user_id') : new Collection;
            $nextSlot = [];

            DB::table('custom_minions')->chunkById(self::CHUNK, function (Collection $minions) use ($fighterNames, &$nextSlot): void {
                $rows = [];

                foreach ($minions as $minion) {
                    $slot = $nextSlot[$minion->user_id] = ($nextSlot[$minion->user_id] ?? 0) + 1;
                    $name = $minion->source === 'fighter' ? ($fighterNames[$minion->user_id] ?? "Minion {$slot}") : "Minion {$slot}";

                    $rows[] = $this->row($minion->user_id, 'minion', 'current', $slot, $minion->version_hash, true, [
                        'name' => $name,
                        'animations' => json_decode($minion->animations, true),
                        'body_px' => (int) $minion->body_px,
                        'source' => $minion->source,
                    ], $minion->created_at, $minion->updated_at);
                }

                DB::table('custom_sprites')->insert($rows);
            });
        }

        if (Schema::hasTable('custom_minion_drafts')) {
            DB::table('custom_minion_drafts')->chunkById(self::CHUNK, function (Collection $drafts): void {
                DB::table('custom_sprites')->insert($drafts->map(fn (object $draft): array => $this->row(
                    $draft->user_id, 'minion', 'draft', null, $draft->version_hash, true,
                    ['name' => null, 'animations' => json_decode($draft->animations, true), 'body_px' => (int) $draft->body_px, 'source' => 'upload'],
                    $draft->created_at, $draft->updated_at,
                ))->all());
            });
        }
    }

    /**
     * One `custom_sprites` row, ready to insert.
     *
     * @param  int  $userId  the owner
     * @param  string  $type  `fighter` or `minion`
     * @param  string  $state  `draft`, `current` or `previous`
     * @param  int|null  $slot  the slot, null for a draft
     * @param  string  $hash  the version hash
     * @param  bool  $enabled  whether a fighter `current` row is equipped; true on every other row (see `up()`)
     * @param  array<string, mixed>  $data  what lives in the `data` column
     * @param  string|null  $createdAt  the row's original creation time
     * @param  string|null  $updatedAt  the row's original update time
     * @return array<string, mixed>
     */
    private function row(int $userId, string $type, string $state, ?int $slot, string $hash, bool $enabled, array $data, ?string $createdAt, ?string $updatedAt): array
    {
        return [
            'user_id' => $userId,
            'type' => $type,
            'state' => $state,
            'slot' => $slot,
            'version_hash' => $hash,
            'enabled' => $enabled,
            'data' => json_encode($data),
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * Recreate the three fighter tables exactly as the earlier migrations made them.
     *
     * @return void
     */
    private function createFighterTables(): void
    {
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
    }

    /**
     * Copy each fighter's `current` row back into `custom_characters`, and every
     * `current` and `previous` row of it into `custom_character_versions` (the old
     * history held the version in use too).
     *
     * @return void
     */
    private function restoreFighters(): void
    {
        DB::table('custom_sprites')->where('type', 'fighter')->where('state', 'current')->chunkById(self::CHUNK, function (Collection $currents): void {
            $characterIds = [];

            foreach ($currents as $current) {
                $data = json_decode($current->data, true);
                $characterIds[$current->user_id] = DB::table('custom_characters')->insertGetId([
                    'user_id' => $current->user_id,
                    'name' => $data['name'] ?? 'Custom',
                    'version_hash' => $current->version_hash,
                    'animations' => json_encode($data['animations'] ?? []),
                    'active' => (bool) $current->enabled,
                    'created_at' => $current->created_at,
                    'updated_at' => $current->updated_at,
                ]);
            }

            $versions = DB::table('custom_sprites')
                ->where('type', 'fighter')
                ->whereIn('state', ['current', 'previous'])
                ->whereIn('user_id', array_keys($characterIds))
                ->orderBy('id')
                ->get();

            DB::table('custom_character_versions')->insert($versions->map(fn (object $version): array => [
                'custom_character_id' => $characterIds[$version->user_id],
                'version_hash' => $version->version_hash,
                'animations' => json_encode(json_decode($version->data, true)['animations'] ?? []),
                'created_at' => $version->created_at,
                'updated_at' => $version->updated_at,
            ])->all());
        });
    }

    /**
     * Copy each fighter draft back into `custom_character_drafts`.
     *
     * @return void
     */
    private function restoreFighterDrafts(): void
    {
        DB::table('custom_sprites')->where('type', 'fighter')->where('state', 'draft')->chunkById(self::CHUNK, function (Collection $drafts): void {
            DB::table('custom_character_drafts')->insert($drafts->map(fn (object $draft): array => [
                'user_id' => $draft->user_id,
                'version_hash' => $draft->version_hash,
                'animations' => json_encode(json_decode($draft->data, true)['animations'] ?? []),
                'created_at' => $draft->created_at,
                'updated_at' => $draft->updated_at,
            ])->all());
        });
    }
};
