<?php

namespace App\Models;

use App\Services\AccountProvisioningService;
use Database\Factories\AccountReserveTokenFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Claude token an admin minted ahead of time for one org account and has
 * not yet handed to any device. Assigning it turns it into an
 * {@see AccountProvisionedGrant} (see
 * {@see AccountProvisioningService::provisionFromReserve()}). Used, expired
 * and discarded rows are kept for audit, only filtered out of
 * {@see scopeAvailable()}.
 */
class AccountReserveToken extends Model
{
    /** @use HasFactory<AccountReserveTokenFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * Attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_expires_at' => 'datetime',
            'session_expires_at' => 'datetime',
            'session_expires_at_estimated' => 'boolean',
            'used_at' => 'datetime',
            'discarded_at' => 'datetime',
        ];
    }

    /**
     * The org account this token belongs to.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * The admin who minted it.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The grant this token became once assigned.
     *
     * @return BelongsTo<AccountProvisionedGrant, $this>
     */
    public function usedGrant(): BelongsTo
    {
        return $this->belongsTo(AccountProvisionedGrant::class, 'used_grant_id');
    }

    /**
     * Scope to tokens that can still be assigned, soonest-expiring first.
     *
     * @param  Builder<self>  $query  the builder being scoped
     * @return Builder<self>
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->whereNull('used_at')
            ->whereNull('discarded_at')
            ->where('session_expires_at', '>', now())
            ->orderBy('session_expires_at');
    }

    /**
     * Whether this token can still be assigned — the per-row twin of
     * {@see scopeAvailable()}.
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        return $this->used_at === null
            && $this->discarded_at === null
            && $this->session_expires_at->isFuture();
    }

    /**
     * The status shown on the Reserve tab.
     *
     * @return string 'Available' | 'Used' | 'Discarded' | 'Expired'
     */
    public function statusLabel(): string
    {
        return match (true) {
            $this->used_at !== null => 'Used',
            $this->discarded_at !== null => 'Discarded',
            $this->session_expires_at->isPast() => 'Expired',
            default => 'Available',
        };
    }
}
