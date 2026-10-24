<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Where a price feed stands: what was last applied and when it was checked.
 */
class PriceFeedState extends Model
{
    /**
     * Columns written by the price sync.
     *
     * @var list<string>
     */
    protected $fillable = [
        'feed',
        'etag',
        'sha256',
        'checked_at',
        'changed_at',
    ];

    /**
     * Attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'changed_at' => 'datetime',
        ];
    }
}
