<?php

namespace App\Models;

use Database\Factories\BossFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Boss extends Model
{
    /** @use HasFactory<BossFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_hp' => 'integer',
            'current_hp' => 'integer',
            'spawned_at' => 'datetime',
            'defeated_at' => 'datetime',
        ];
    }

    public function killingBlowUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'killing_blow_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }
}
