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
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
            'is_admin' => 'boolean',
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
        return $this->belongsToMany(Account::class)->withTimestamps();
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
     * Single source of truth for admin authorization — the `admin` gate and
     * Filament's panel access both delegate here so the rule can never drift
     * between the two entry points.
     *
     * @return bool
     */
    public function isAdministrator(): bool
    {
        return (bool) $this->is_admin;
    }

    /**
     * @inheritDoc
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isAdministrator();
    }
}
