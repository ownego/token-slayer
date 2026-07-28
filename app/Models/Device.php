<?php

namespace App\Models;

use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A physical machine of a user, identified by a client-generated
 * fingerprint. `device_id = 'default'` is the legacy sentinel; NULL marks an
 * admin-opened placeholder awaiting its first claim. Grants attach to
 * devices, never directly to users.
 */
class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use HasFactory;

    /**
     * The legacy sentinel name: the device row migrated from pre-device data
     * or auto-created on the first-ever provision of a zero-device user.
     *
     * @var string
     */
    public const string DEFAULT_NAME = 'default';

    protected $guarded = [];

    /**
     * The user this machine belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Every provisioned grant issued to this machine, any status.
     *
     * @return HasMany<AccountProvisionedGrant, $this>
     */
    public function grants(): HasMany
    {
        return $this->hasMany(AccountProvisionedGrant::class);
    }
}
