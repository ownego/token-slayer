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
 * A built fighter its player has not confirmed yet: what they preview before
 * it goes live. The fighter `draft` row of `custom_sprites`; one per user, and
 * a new build replaces it.
 *
 * @property array<string, mixed> $data the JSON column behind `animations`
 * @property array<string, array{frames: int, rate: int}> $animations each strip's frame count and playback rate
 */
class CustomCharacterDraft extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
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
     * Limit every query to fighter drafts, and stamp new rows as exactly that.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::addGlobalScope('fighter-draft', function (Builder $builder): void {
            $builder
                ->where($builder->qualifyColumn('type'), CustomSpriteType::Fighter->value)
                ->where($builder->qualifyColumn('state'), CustomSpriteState::Draft->value);
        });

        static::creating(function (CustomCharacterDraft $draft): void {
            $draft->type = CustomSpriteType::Fighter;
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
