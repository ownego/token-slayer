<?php

namespace App\Models;

use App\Enums\CustomSpriteState;
use App\Enums\CustomSpriteType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A built fighter its player has not confirmed yet: what they preview before
 * it goes live. The fighter `draft` row of `custom_sprites`; one per user, and
 * a new build replaces it.
 *
 * `enabled` is always true on a draft and never read.
 */
class CustomCharacterDraft extends CustomSprite
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

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
}
