<?php

namespace App\Models;

use App\Enums\CustomSpriteState;
use App\Enums\CustomSpriteType;
use App\Services\CustomCharacter\CustomCharacterStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user's own fighter, built from a pose sheet they uploaded: the `current` fighter
 * row of `custom_sprites`. Holds the version in use (by hash) and how to animate it;
 * every confirmed version the player can switch back to is in `versions`, and `active`
 * says whether the fighter is the one equipped (the `enabled` column).
 *
 * `name` and `animations` live in the row's `data` and are read and written through the
 * accessors of `CustomSprite`, so the model is used as before; a query builder sees only
 * real columns (`enabled`, `version_hash`, ...), never `name` or `animations`.
 *
 * On this row `enabled` is the one column that means something: the fighter is equipped.
 *
 * @property string|null $name what the player called the fighter
 * @property bool $active whether the fighter is the one equipped
 */
class CustomCharacter extends CustomSprite
{
    /** @use HasFactory<\Database\Factories\CustomCharacterFactory> */
    use HasFactory;

    /**
     * The key the fighter sheet's roster and the equipped fighter use for the custom slot.
     *
     * @var string
     */
    public const string SHEET_KEY = 'custom';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'version_hash',
        'animations',
        'active',
    ];

    /**
     * Limit every query to the fighter in use, and stamp new rows as exactly that.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::addGlobalScope('fighter-current', function (Builder $builder): void {
            $builder
                ->where($builder->qualifyColumn('type'), CustomSpriteType::Fighter->value)
                ->where($builder->qualifyColumn('state'), CustomSpriteState::Current->value);
        });

        static::creating(function (CustomCharacter $character): void {
            $character->type = CustomSpriteType::Fighter;
            $character->state = CustomSpriteState::Current;
            $character->slot ??= 1;
            $character->enabled ??= true;
            $character->data ??= [];
        });
    }

    /**
     * The user this character belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The confirmed versions of this fighter, the one in use included, oldest first.
     *
     * @return HasMany<CustomCharacterVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(CustomCharacterVersion::class, 'user_id', 'user_id')->orderBy('id');
    }

    /**
     * Move this row into the history: it stops being the fighter in use and loses the
     * name, which belongs to whichever version is in use.
     *
     * The name is not kept per version: it goes with the fighter, so it is dropped here
     * and `CustomCharacterVersion::promote()` writes it back onto whichever row takes
     * over. After the call this instance is a `previous` row, which the global scope of this
     * class no longer matches: `CustomCharacter` queries will not return it. Do not use it
     * again as the fighter in use (`active`, `broadcastState()`, `sheetState()`) and do not
     * `refresh()` it (that reloads the row, still typed as the fighter in use, even though
     * it is a history row now). Load the row through `CustomCharacterVersion` if it is
     * needed.
     *
     * @return void
     */
    public function demote(): void
    {
        $this->forceFill([
            'state' => CustomSpriteState::Previous,
            'enabled' => true,
            'data' => ['animations' => $this->animations],
        ])->save();
    }

    /**
     * What a client needs to load and animate this fighter: the version (a new
     * one means new files), where its strips are served from and the animation
     * table. The base is a same-origin path on the app's own disk, a full URL on
     * a disk with another host (see CustomCharacterStore::baseUrl()).
     *
     * @return array{version: string, url_base: string, animations: array<string, array{frames: int, rate: int}>}
     */
    public function broadcastState(): array
    {
        return [
            'version' => $this->version_hash,
            'url_base' => app(CustomCharacterStore::class)->baseUrl($this->user_id, $this->version_hash),
            'animations' => $this->animations,
        ];
    }

    /**
     * What the fighter sheet's Character tab needs: the broadcast state plus the
     * name the player gave the fighter, whether it is the equipped one and who made
     * it. The author travels only here (the authenticated sheet payload), never in
     * `broadcastState()`, which goes out on the public battlefield channel.
     *
     * @return array{version: string, url_base: string, animations: array<string, array{frames: int, rate: int}>, name: string, active: bool, author: string}
     */
    public function sheetState(): array
    {
        return [
            ...$this->broadcastState(),
            'name' => $this->name,
            'active' => (bool) $this->active,
            'author' => $this->loadMissing('user')->user->displayHandle(),
        ];
    }

    /**
     * Whether the fighter is the one equipped: the `enabled` column under its older name.
     *
     * @return Attribute<bool, bool>
     */
    protected function active(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => (bool) $this->enabled,
            set: fn (bool $value): array => ['enabled' => $value],
        );
    }
}
