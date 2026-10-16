<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One confirmed version of a custom fighter: its strips live in a folder named
 * by `version_hash`, `animations` records each strip's frame count and rate.
 */
class CustomCharacterVersion extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'custom_character_id',
        'version_hash',
        'animations',
    ];

    /**
     * The fighter this version belongs to.
     *
     * @return BelongsTo<CustomCharacter, $this>
     */
    public function customCharacter(): BelongsTo
    {
        return $this->belongsTo(CustomCharacter::class);
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
        ];
    }
}
