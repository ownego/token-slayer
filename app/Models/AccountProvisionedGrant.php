<?php

namespace App\Models;

use App\Enums\GrantStatus;
use App\Support\CacheKeys;
use Database\Factories\AccountProvisionedGrantFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A provisioned OAuth grant issued to one device for one org account. The
 * raw secret lives only in the cache ({@see CacheKeys::provisionedGrant()},
 * 24 h TTL); this row is the durable audit and lifecycle record.
 */
class AccountProvisionedGrant extends Model
{
    /** @use HasFactory<AccountProvisionedGrantFactory> */
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
            'status' => GrantStatus::class,
            'provisioned_at' => 'datetime',
            'claimed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'deprovisioned_at' => 'datetime',
        ];
    }

    /**
     * The org account this grant belongs to.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * The device this grant was issued to.
     *
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * Scope to grants that are not revoked (Pending or Claimed).
     *
     * @param  Builder<self>  $query  the builder being scoped
     * @return Builder<self>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', '!=', GrantStatus::Revoked->value);
    }

    /**
     * Per-user devices summary for one account: "claimed/total set up"
     * counting non-revoked grants on the user's devices for that account,
     * or null when the user holds none.
     *
     * @param  int  $accountId  the account being summarized
     * @param  int  $userId  the member user
     * @return string|null e.g. "1/2 set up"
     */
    public static function deviceSummaryFor(int $accountId, int $userId): ?string
    {
        $grants = self::query()->live()
            ->where('account_id', $accountId)
            ->whereHas('device', fn ($query) => $query->where('user_id', $userId))
            ->get();
        if ($grants->isEmpty()) {
            return null;
        }

        $claimed = $grants->where('status', GrantStatus::Claimed)->count();

        return "{$claimed}/{$grants->count()} set up";
    }
}
