<?php

namespace App\Models;

use App\Enums\CustomSpriteState;
use App\Enums\CustomSpriteType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One confirmed version of a custom fighter, the one in use included: a fighter row
 * of `custom_sprites` that is `current` or `previous`. Its strips live in a folder
 * named by `version_hash`, `animations` records each strip's frame count and rate.
 *
 * @property array<string, mixed> $data the JSON column behind `animations`
 * @property array<string, array{frames: int, rate: int}> $animations each strip's frame count and playback rate
 * @property CustomSpriteState $state whether this is the version in use or an earlier one
 */
class CustomCharacterVersion extends Model
{
    /** @use HasFactory<\Database\Factories\CustomCharacterVersionFactory> */
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
        'animations',
    ];

    /**
     * Limit every query to fighter versions in use or earlier, and stamp new rows as
     * a fighter's earlier version unless the state is set.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::addGlobalScope('fighter-saved', function (Builder $builder): void {
            $builder
                ->where($builder->qualifyColumn('type'), CustomSpriteType::Fighter->value)
                ->whereIn($builder->qualifyColumn('state'), [CustomSpriteState::Current->value, CustomSpriteState::Previous->value]);
        });

        static::creating(function (CustomCharacterVersion $version): void {
            $version->type = CustomSpriteType::Fighter;
            $version->state ??= CustomSpriteState::Previous;
            $version->slot ??= 1;
            $version->enabled ??= true;
            $version->data ??= [];
        });
    }

    /**
     * The player this version belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Make this earlier version the fighter in use, equipped, under the given name.
     *
     * @param  string  $name  the fighter's name, carried over from the version it replaces
     * @param  array<string, array{frames: int, rate: int}>  $animations  the animation table to keep
     * @return void
     */
    public function promote(string $name, array $animations): void
    {
        $this->forceFill([
            'state' => CustomSpriteState::Current,
            'enabled' => true,
            'data' => ['name' => $name, 'animations' => $animations],
        ])->save();
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
