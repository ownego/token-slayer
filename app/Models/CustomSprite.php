<?php

namespace App\Models;

use App\Enums\CustomMinionSource;
use App\Enums\CustomSpriteState;
use App\Enums\CustomSpriteType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * What the five custom sprite models share: a row of `custom_sprites`. Each subclass
 * is one slice of that table (the fighter in use, a fighter's saved version, a fighter
 * draft, a minion, a minion draft) cut out by its own global scope; this class holds the
 * table, the casts, the accessors over the JSON `data` column and the local scopes that
 * pick a slice by hand.
 *
 * It is a parent class and not a single model on purpose: Filament Shield names its
 * permissions after the resource's model class (`*:CustomCharacter`, `*:CustomMinion`)
 * and a model has one policy, so each slice that has a resource keeps its own class.
 * The parent has no global scope and no `creating` stamp (a subclass sets its own), so
 * it is abstract and is never queried itself.
 *
 * What `enabled` means depends on `type` and `state`:
 * - fighter `current`: the fighter is equipped (`CustomCharacter::$active`).
 * - fighter `previous` and `draft`: always true, never read.
 * - minion `current` and `draft`: always true, never read. Whether a minion is ticked
 *   into the player's pool lives in `users.minion_pool`, not on the row.
 *
 * `name`, `animations`, `body_px` and `source` are read and written through the
 * accessors below; a query builder sees only real columns (`slot`, `enabled`,
 * `version_hash`, ...), never those four. A subclass uses the ones that make sense for
 * it: a fighter has no `body_px` and no `source`.
 *
 * @property int $id
 * @property int $user_id the owner
 * @property CustomSpriteType $type fighter or minion
 * @property CustomSpriteState $state draft, current or previous
 * @property int|null $slot the minion slot, 1 for a fighter, null for a draft that adds
 * @property string $version_hash the folder the strips live in
 * @property bool $enabled equipped for a fighter `current` row; always true on every other row (see above)
 * @property array<string, mixed> $data the JSON column behind the accessors below
 * @property string|null $name what the sprite is called, when it has a name
 * @property array<string, array{frames: int, rate: int}> $animations each strip's frame count and playback rate
 * @property int $body_px the measured height, in pixels, of the idle frame's ink (minions)
 * @property CustomMinionSource|null $source how the minion came to be (minions)
 */
abstract class CustomSprite extends Model
{
    /**
     * The table every custom fighter and minion version lives in.
     *
     * @var string
     */
    protected $table = 'custom_sprites';

    /**
     * Limit a query to fighter rows.
     *
     * @param  Builder<static>  $query  the query being built
     * @return Builder<static>
     */
    public function scopeFighter(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('type'), CustomSpriteType::Fighter->value);
    }

    /**
     * Limit a query to minion rows.
     *
     * @param  Builder<static>  $query  the query being built
     * @return Builder<static>
     */
    public function scopeMinion(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('type'), CustomSpriteType::Minion->value);
    }

    /**
     * Limit a query to rows in use: the equipped fighter, or a minion holding a slot.
     *
     * @param  Builder<static>  $query  the query being built
     * @return Builder<static>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('state'), CustomSpriteState::Current->value);
    }

    /**
     * Limit a query to rows built but not yet confirmed.
     *
     * @param  Builder<static>  $query  the query being built
     * @return Builder<static>
     */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('state'), CustomSpriteState::Draft->value);
    }

    /**
     * Limit a query to fighter versions the player can switch back to.
     *
     * @param  Builder<static>  $query  the query being built
     * @return Builder<static>
     */
    public function scopePrevious(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('state'), CustomSpriteState::Previous->value);
    }

    /**
     * What the sprite is called, kept in `data`.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->data['name'] ?? null,
            set: fn (?string $value): array => $this->dataWith('name', $value),
        );
    }

    /**
     * Each strip's frame count and playback rate, kept in `data`.
     *
     * @return Attribute<array<string, array{frames: int, rate: int}>, array<string, array{frames: int, rate: int}>>
     */
    protected function animations(): Attribute
    {
        return Attribute::make(
            get: fn (): array => $this->data['animations'] ?? [],
            set: fn (array $value): array => $this->dataWith('animations', $value),
        );
    }

    /**
     * The measured height, in pixels, of the idle frame's ink, kept in `data`.
     *
     * @return Attribute<int, int>
     */
    protected function bodyPx(): Attribute
    {
        return Attribute::make(
            get: fn (): int => (int) ($this->data['body_px'] ?? 0),
            set: fn (int $value): array => $this->dataWith('body_px', $value),
        );
    }

    /**
     * How the minion came to be, kept in `data` as its string value.
     *
     * @return Attribute<CustomMinionSource|null, CustomMinionSource|string>
     */
    protected function source(): Attribute
    {
        return Attribute::make(
            get: fn (): ?CustomMinionSource => CustomMinionSource::tryFrom((string) ($this->data['source'] ?? '')),
            set: fn (CustomMinionSource|string $value): array => $this->dataWith('source', $value instanceof CustomMinionSource ? $value->value : $value),
        )->withoutObjectCaching();
    }

    /**
     * The `data` attribute a setter writes: the current `data` with one key set.
     *
     * @param  string  $key  the key inside `data`
     * @param  mixed  $value  the value to store under it
     * @return array{data: string}
     */
    private function dataWith(string $key, mixed $value): array
    {
        return ['data' => $this->castAttributeAsJson('data', [...($this->data ?? []), $key => $value])];
    }

    /**
     * Cast the stored columns into their PHP types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CustomSpriteType::class,
            'state' => CustomSpriteState::class,
            'slot' => 'integer',
            'enabled' => 'boolean',
            'data' => 'array',
        ];
    }
}
