<?php

namespace App\Models;

use Database\Factories\FighterPositionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Durable source of truth for a user's last battlefield position — the DB
 * backing behind FighterPositionCache's fast-read cache layer.
 */
class FighterPosition extends Model
{
    /** @use HasFactory<FighterPositionFactory> */
    use HasFactory;

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var string
     */
    protected $primaryKey = 'user_id';

    /**
     * @var array<int, string>
     */
    protected $fillable = ['user_id', 'x', 'y'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'x' => 'float',
            'y' => 'float',
        ];
    }
}
