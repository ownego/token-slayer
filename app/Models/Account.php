<?php

namespace App\Models;

use App\Services\AccountResolver;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

#[Hidden(['oauth_access_token', 'oauth_refresh_token'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    /** Account is connected and probeable. */
    public const string STATUS_ACTIVE = 'active';

    /** Refresh token died — admin must re-run the Connect flow. */
    public const string STATUS_NEEDS_REAUTH = 'needs_reauth';

    /** Soft-disabled by admin; prober skips it. */
    public const string STATUS_DISABLED = 'disabled';

    protected $guarded = [];

    /**
     * The model's default attribute values, mirroring the migration's DB-level defaults
     * so a freshly-created instance reflects 'active' status without a reload.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    /**
     * Keep the resolver's email map in sync with account mutations.
     */
    protected static function booted(): void
    {
        $flush = fn () => Cache::forget(AccountResolver::CACHE_KEY);
        static::saved($flush);
        static::deleted($flush);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    protected static function newFactory(): AccountFactory
    {
        return AccountFactory::new();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'oauth_access_token' => 'encrypted',
            'oauth_refresh_token' => 'encrypted',
            'oauth_expires_at' => 'datetime',
            'last_probed_at' => 'datetime',
        ];
    }
}
