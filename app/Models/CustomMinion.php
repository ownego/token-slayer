<?php

namespace App\Models;

use App\Enums\CustomSpriteState;
use App\Enums\CustomSpriteType;
use App\Services\CustomCharacter\CustomMinionStore;
use Database\Factories\CustomMinionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of a player's custom minions, built from a pose sheet (or copied from their
 * fighter): a minion `current` row of `custom_sprites`. A player holds up to
 * `custom_character.minions_max` of them, each in its own slot; each subagent they
 * dispatch summons one at random from the ones ticked in their pool. Rows are immutable
 * apart from `name`: a different look replaces the row, never edits it.
 *
 * `name`, `animations`, `body_px` and `source` live in the row's `data` and are read
 * and written through the accessors of `CustomSprite`; a query builder sees only real
 * columns (`slot`, `enabled`, `version_hash`, ...).
 *
 * `enabled` is always true on a minion row and never read: whether the minion is ticked
 * is not a property of the row but of the player, in `users.minion_pool`.
 *
 * @property int $slot the slot the minion holds, from 1 to `minions_max`
 * @property string|null $name what the minion is called; it follows the slot
 */
class CustomMinion extends CustomSprite
{
    /** @use HasFactory<CustomMinionFactory> */
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
}
