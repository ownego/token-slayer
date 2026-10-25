<?php

namespace App\Models;

use App\Enums\CustomMinionSource;
use App\Enums\CustomSpriteState;
use App\Enums\CustomSpriteType;
use Database\Factories\CustomMinionDraftFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A built minion its player has not added yet: what they preview before it joins
 * their set. The minion `draft` row of `custom_sprites`; one per user, and a new
 * build replaces it. `slot` is the slot it will take over when it replaces a
 * minion, null when it is a new one; `name` is the fighter's name for a draft
 * copied from the fighter, null for an upload.
 *
 * @property array<string, mixed> $data the JSON column behind the accessors below
 * @property int|null $slot the slot this draft will replace, null when it adds a minion
 * @property string|null $name the name it will carry, null for an upload
 * @property array<string, array{frames: int, rate: int}> $animations each strip's frame count and playback rate
 * @property int $body_px the measured height, in pixels, of the idle frame's ink
 * @property CustomMinionSource|null $source how the minion was made
 */
class CustomMinionDraft extends Model
{
    /** @use HasFactory<CustomMinionDraftFactory> */
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
        'name',
        'animations',
        'body_px',
        'source',
    ];

    /**
     * Limit every query to minion drafts, and stamp new rows as exactly that.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::addGlobalScope('minion-draft', function (Builder $builder): void {
            $builder
                ->where($builder->qualifyColumn('type'), CustomSpriteType::Minion->value)
                ->where($builder->qualifyColumn('state'), CustomSpriteState::Draft->value);
        });

        static::creating(function (CustomMinionDraft $draft): void {
            $draft->type = CustomSpriteType::Minion;
            $draft->state = CustomSpriteState::Draft;
            $draft->enabled ??= true;
            $draft->data ??= [];
        });
    }

    /**
     * The player who built it.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The name the minion will carry, kept in `data`; null for an upload.
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
     * How the minion was made, kept in `data` as its string value.
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
