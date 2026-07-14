<?php

namespace App\Models;

use Database\Factories\AccountUsageSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single append-only quota-utilization reading for an org account,
 * captured once per 5-minute prober cycle from the Anthropic usage API.
 * Rows are never updated after creation — history lives in the ledger,
 * never in a mutable "current usage" column.
 */
class AccountUsageSnapshot extends Model
{
    /** @use HasFactory<AccountUsageSnapshotFactory> */
    use HasFactory;

    /**
     * Snapshot rows are append-only and have no `updated_at` column;
     * disabling the constant stops Eloquent from touching a nonexistent
     * column on save.
     *
     * @var string|null
     */
    public const ?string UPDATED_AT = null;

    /**
     * Snapshots are written only by the prober from trusted parsed values, so
     * no attribute is mass-assignment guarded.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'util_5h' => 'integer',
            'util_7d' => 'integer',
            'util_7d_sonnet' => 'integer',
            'util_7d_oi' => 'integer',
            'reset_5h_at' => 'datetime',
            'reset_7d_at' => 'datetime',
            'raw' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The org account this quota reading belongs to.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return AccountUsageSnapshotFactory
     */
    protected static function newFactory(): AccountUsageSnapshotFactory
    {
        return AccountUsageSnapshotFactory::new();
    }
}
