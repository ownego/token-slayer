<?php

namespace App\Models;

use Database\Factories\MissingModelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A model id seen on a complete event that had no price at the time. The
 * row lives until the price sync finds a price and fills those events.
 */
class MissingModel extends Model
{
    /** @use HasFactory<MissingModelFactory> */
    use HasFactory;

    /**
     * Columns written by the recorder.
     *
     * @var list<string>
     */
    protected $fillable = [
        'model',
        'first_seen_at',
        'last_seen_at',
    ];

    /**
     * Attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
