<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Services\AccountResolver;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Cache;

#[Hidden(['oauth_access_token', 'oauth_refresh_token'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * The model's default attribute values, mirroring the migration's DB-level defaults
     * so a freshly-created instance reflects 'active' status without a reload.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => AccountStatus::Active->value,
    ];

    /**
     * Keep the resolver's email and organization-uuid maps in sync with account mutations.
     *
     * @return void
     */
    protected static function booted(): void
    {
        $flush = function (): void {
            Cache::forget(AccountResolver::CACHE_KEY);
            Cache::forget(AccountResolver::ORG_CACHE_KEY);
        };
        static::saved($flush);
        static::deleted($flush);
    }

    /**
     * Users who are members of this org account, via the account_user pivot.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * Every quota-utilization snapshot recorded for this account by the
     * 5-minute prober, in natural (insertion) order. Callers that need
     * newest-first should order the query explicitly.
     *
     * @return HasMany<AccountUsageSnapshot, $this>
     */
    public function usageSnapshots(): HasMany
    {
        return $this->hasMany(AccountUsageSnapshot::class);
    }

    /**
     * The most recently recorded quota-utilization snapshot for this
     * account, resolved via `latestOfMany` on `created_at`.
     *
     * @return HasOne<AccountUsageSnapshot, $this>
     */
    public function latestUsageSnapshot(): HasOne
    {
        return $this->hasOne(AccountUsageSnapshot::class)->latestOfMany('created_at');
    }

    /**
     * Scope to accounts the usage prober should attempt this cycle: not
     * soft-disabled, not already known to have a dead refresh token
     * (`NeedsReauth` accounts are skipped until reconnected), and holding
     * a refresh token to exchange in the first place.
     *
     * @param  Builder<Account>  $query  the query being scoped
     * @return Builder<Account> the scoped query
     */
    public function scopeProbeable(Builder $query): Builder
    {
        return $query
            ->where('status', '!=', AccountStatus::Disabled->value)
            ->where('status', '!=', AccountStatus::NeedsReauth->value)
            ->whereNotNull('oauth_refresh_token');
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
            'status' => AccountStatus::class,
            'oauth_access_token' => 'encrypted',
            'oauth_refresh_token' => 'encrypted',
            'oauth_expires_at' => 'datetime',
            'last_probed_at' => 'datetime',
        ];
    }
}
