<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot model for the `account_user` membership table, so the `status`
 * column casts to {@see MembershipStatus} when accessed through the
 * `Account::users()` relationship.
 */
class AccountUser extends Pivot
{
    /**
     * The membership pivot table.
     *
     * @var string
     */
    protected $table = 'account_user';

    /**
     * The pivot table has its own auto-incrementing id column.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * Attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'provisioned_at' => 'datetime',
            'claimed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
