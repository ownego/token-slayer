<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\FighterCharacter;
use App\Enums\MembershipStatus;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'slack_user_id',
        'slack_handle',
        'display_name',
        'avatar_url',
        'hook_token',
        'last_event_at',
        'client_version',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_event_at' => 'datetime',
        ];
    }

    public function displayHandle(): string
    {
        return $this->slack_handle ?: ($this->display_name ?: ($this->name ?: ('#'.$this->id)));
    }

    public function characterForBoss(?int $bossId): string
    {
        return FighterCharacter::forUserAndBoss($this->id, $bossId)->value;
    }

    /**
     * Org accounts this user is a member of.
     *
     * @return BelongsToMany<Account, $this>
     */
    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(Account::class)
            ->using(AccountUser::class)
            ->withPivot('token_uuid', 'provisioned_at', 'claimed_at', 'revoked_at')
            ->withTimestamps();
    }

    /**
     * The accounts this user is a tracked member of
     * (`account_user.status = tracked`).
     *
     * @return BelongsToMany<Account, $this>
     */
    public function trackedAccounts(): BelongsToMany
    {
        return $this->accounts()->wherePivot('status', MembershipStatus::Tracked->value);
    }

    /**
     * Usage events this user has logged, across all accounts, in natural
     * order. Callers that need newest-first order the query explicitly.
     *
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * Whether this user can reach the admin panel — any assigned role grants
     * entry; which Resources/actions they can actually use inside the panel
     * is governed per-permission by Shield's generated Policies, not here.
     *
     * @return bool
     */
    public function isAdministrator(): bool
    {
        return $this->roles()->exists();
    }

    /**
     * @inheritDoc
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isAdministrator();
    }
}
