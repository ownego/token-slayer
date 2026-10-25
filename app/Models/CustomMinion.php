<?php

namespace App\Models;

use App\Enums\CustomMinionSource;
use App\Enums\CustomSpriteState;
use App\Enums\CustomSpriteType;
use App\Services\CustomCharacter\CustomMinionStore;
use Database\Factories\CustomMinionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of a player's custom minions, built from a pose sheet (or copied from their
 * fighter): a minion `current` row of `custom_sprites`. A player holds up to
 * `custom_character.minions_max` of them, each in its own slot; each subagent they
 * dispatch summons one at random from the ticked ones. Rows are immutable apart from
 * `name` and `enabled`: a different look replaces the row, never edits it.
 *
 * `name`, `animations`, `body_px` and `source` live in the row's `data` and are read
 * and written through accessors; a query builder sees only real columns (`slot`,
 * `enabled`, `version_hash`, ...).
 *
 * @property array<string, mixed> $data the JSON column behind the accessors below
 * @property int $slot the slot the minion holds, from 1 to `minions_max`
 * @property bool $enabled whether the player has ticked the minion into their pool
 * @property string|null $name what the minion is called; it follows the slot
 * @property array<string, array{frames: int, rate: int}> $animations each strip's frame count and playback rate
 * @property int $body_px the measured height, in pixels, of the idle frame's ink
 * @property CustomMinionSource|null $source how the minion came to be in the set
 */
class CustomMinion extends Model
{
    /** @use HasFactory<CustomMinionFactory> */
    use HasFactory;

    /**
     * The table every custom fighter and minion version lives in.
     *
     * @var string
     */
    protected $table = 'custom_sprites';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'version_hash',
        'slot',
        'enabled',
        'name',
        'animations',
        'body_px',
        'source',
    ];

    /**
     * Limit every query to minions in a slot, and stamp new rows as exactly that.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::addGlobalScope('minion-current', function (Builder $builder): void {
            $builder
                ->where($builder->qualifyColumn('type'), CustomSpriteType::Minion->value)
                ->where($builder->qualifyColumn('state'), CustomSpriteState::Current->value);
        });

        static::creating(function (CustomMinion $minion): void {
            $minion->type = CustomSpriteType::Minion;
            $minion->state = CustomSpriteState::Current;
            $minion->enabled ??= true;
            $minion->data ??= [];
        });
    }

    /**
     * The user this minion belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * What a client needs to load and draw this minion: the version (a new one
     * means new files), where its strips are served from, the animation table and
     * how tall its body is. Built from the owner's id alone, so the owner is never loaded.
     *
     * @return array{version: string, url_base: string, animations: array<string, array{frames: int, rate: int}>, body_px: int}
     */
    public function broadcastState(): array
    {
        return [
            'version' => $this->version_hash,
            'url_base' => app(CustomMinionStore::class)->baseUrl($this->user_id, $this->version_hash),
            'animations' => $this->animations,
            'body_px' => $this->body_px,
        ];
    }

    /**
     * What the minion is called, kept in `data`. It follows the slot, so replacing
     * the minion keeps it.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->data['name'] ?? null,
            set: fn (?string $value): array => ['data' => $this->castAttributeAsJson('data', [...($this->data ?? []), 'name' => $value])],
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
            set: fn (array $value): array => ['data' => $this->castAttributeAsJson('data', [...($this->data ?? []), 'animations' => $value])],
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
            set: fn (int $value): array => ['data' => $this->castAttributeAsJson('data', [...($this->data ?? []), 'body_px' => $value])],
        );
    }

    /**
     * How the minion came to be in the set, kept in `data` as its string value.
     *
     * @return Attribute<CustomMinionSource|null, CustomMinionSource|string>
     */
    protected function source(): Attribute
    {
        return Attribute::make(
            get: fn (): ?CustomMinionSource => CustomMinionSource::tryFrom((string) ($this->data['source'] ?? '')),
            set: fn (CustomMinionSource|string $value): array => ['data' => $this->castAttributeAsJson('data', [...($this->data ?? []), 'source' => $value instanceof CustomMinionSource ? $value->value : $value])],
        )->withoutObjectCaching();
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
