<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\MembershipStatus;
use App\Support\CacheKeys;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
     * Keep the resolver's email and organization-uuid maps, and this
     * account's membership aggregate + ingest pair caches, in sync with
     * account mutations.
     *
     * @return void
     */
    protected static function booted(): void
    {
        $flush = function (Account $account): void {
            CacheKeys::forgetAccountMaps();
            CacheKeys::forgetAccountMembership($account->id);
            CacheKeys::forgetMembershipPairs($account->id);
        };
        static::saved($flush);
        static::deleted($flush);
    }

    /**
     * Every developer linked to this org account via the `account_user`
     * pivot, of any membership status. Use {@see trackedUsers()} for the
     * "members" subset.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(AccountUser::class)
            ->withPivot('status')
            ->withTimestamps();
    }

    /**
     * The tracked members of this account (`account_user.status = tracked`).
     *
     * @return BelongsToMany<User, $this>
     */
    public function trackedUsers(): BelongsToMany
    {
        return $this->users()->wherePivot('status', MembershipStatus::Tracked->value);
    }

    /**
     * The untracked contributors of this account (`account_user.status =
     * untracked`) — developers with events here who have not been promoted.
     *
     * @return BelongsToMany<User, $this>
     */
    public function untrackedUsers(): BelongsToMany
    {
        return $this->users()->wherePivot('status', MembershipStatus::Untracked->value);
    }

    /**
     * Users who have had an OAuth grant provisioned for this account
     * (`account_user.provisioned_at` set), regardless of claim/revoke state.
     * Exposes the provisioning audit columns (`token_uuid`, `provisioned_at`,
     * `claimed_at`, `revoked_at`) on the pivot for the provisions relation manager.
     *
     * @return BelongsToMany<User, $this>
     */
    public function provisionedUsers(): BelongsToMany
    {
        return $this->users()
            ->withPivot(['token_uuid', 'provisioned_at', 'claimed_at', 'revoked_at'])
            ->wherePivotNotNull('provisioned_at');
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
     * Every usage event attributed to this org account via
     * `events.account_id`, in natural order. Callers that need newest-first
     * order the query explicitly.
     *
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
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
