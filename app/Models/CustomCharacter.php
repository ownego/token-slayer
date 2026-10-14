<?php

namespace App\Models;

use App\Services\CustomCharacter\CustomCharacterStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's own fighter, built from a pose sheet they uploaded. Holds only
 * where the finished strips live (by version hash) and how to animate them.
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
     * What a client needs to load and animate this fighter: the version (a new
     * one means new files), the folder its strips are served from and the
     * animation table. The folder is a same-origin path on a local disk and a full
     * URL on a remote one (see `CustomCharacterStore::baseUrl()`): the battlefield
     * reads the strips' pixels, so a remote host must send CORS headers.
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
