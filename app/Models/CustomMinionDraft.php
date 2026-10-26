<?php

namespace App\Models;

use App\Enums\CustomSpriteState;
use App\Enums\CustomSpriteType;
use Database\Factories\CustomMinionDraftFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A built minion its player has not added yet: what they preview before it joins
 * their set. The minion `draft` row of `custom_sprites`; one per user, and a new
 * build replaces it. `slot` is the slot it will take over when it replaces a
 * minion, null when it is a new one; `name` is the fighter's name for a draft
 * copied from the fighter, null for an upload. `enabled` is always true on a draft and
 * never read.
 *
 * @property int|null $slot the slot this draft will replace, null when it adds a minion
 * @property string|null $name the name it will carry, null for an upload
 */
class CustomMinionDraft extends CustomSprite
{
    /** @use HasFactory<CustomMinionDraftFactory> */
    use HasFactory;

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
}
