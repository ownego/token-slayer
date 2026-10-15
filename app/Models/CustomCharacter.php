<?php

namespace App\Models;

use App\Services\CustomCharacter\CustomCharacterStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user's own fighter, built from a pose sheet they uploaded. Holds the
 * version in use (by hash) and how to animate it; every confirmed version the
 * player can switch back to is in `versions`, and `active` says whether the
 * fighter is the one equipped.
 *
 * @property array<string, array{frames: int, rate: int}> $animations each strip's frame count and playback rate
 */
class CustomCharacter extends Model
{
    /** @use HasFactory<\Database\Factories\CustomCharacterFactory> */
    use HasFactory;

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
     * The user this character belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The confirmed versions of this fighter, oldest first.
     *
     * @return HasMany<CustomCharacterVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(CustomCharacterVersion::class)->orderBy('id');
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
     * name the player gave the fighter and whether it is the equipped one.
     *
     * @return array{version: string, url_base: string, animations: array<string, array{frames: int, rate: int}>, name: string, active: bool}
     */
    public function sheetState(): array
    {
        return [...$this->broadcastState(), 'name' => $this->name, 'active' => (bool) $this->active];
    }

    /**
     * Cast the stored animation table back into an array.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'animations' => 'array',
            'active' => 'boolean',
        ];
    }
}
