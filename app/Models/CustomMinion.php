<?php

namespace App\Models;

use App\Enums\CustomMinionSource;
use App\Services\CustomCharacter\CustomMinionStore;
use Database\Factories\CustomMinionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of a player's custom minions, built from a pose sheet (or copied from their
 * fighter). A player holds up to `custom_character.minions_max` of them; each
 * subagent they dispatch summons one at random. Rows are immutable: a different
 * look is a different row, never an edit.
 *
 * @property array<string, array{frames: int, rate: int}> $animations each strip's frame count and playback rate
 * @property int $body_px the measured height, in pixels, of the idle frame's ink
 * @property CustomMinionSource $source how the minion came to be in the set
 */
class CustomMinion extends Model
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
        'animations',
        'body_px',
        'source',
    ];

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
     * What the fighter sheet's Minions tab needs: the broadcast state plus the
     * row's id (to remove it) and where it came from.
     *
     * @return array{id: int, version: string, url_base: string, animations: array<string, array{frames: int, rate: int}>, body_px: int, source: string}
     */
    public function sheetState(): array
    {
        return [
            'id' => $this->id,
            ...$this->broadcastState(),
            'source' => $this->source->value,
        ];
    }

    /**
     * Cast the stored columns into their PHP types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'animations' => 'array',
            'body_px' => 'integer',
            'source' => CustomMinionSource::class,
        ];
    }
}
