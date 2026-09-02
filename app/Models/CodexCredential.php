<?php

namespace App\Models;

use App\Enums\AccountStatus;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Codex/ChatGPT shared account's persistent credential — populated by
 * `CodexProvisioningService::connectAccount()` (Step A). Per-device
 * provisioning secrets (Step B) are cache-only and never land here — see
 * `CodexProvisioningService::provisionForDevice()`.
 */
#[Hidden(['codex_access_token', 'codex_refresh_token'])]
class CodexCredential extends Model
{
    protected $guarded = [];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => AccountStatus::Active->value,
    ];

    /**
     * The `Account` envelope this credential belongs to.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
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
            'codex_access_token' => 'encrypted',
            'codex_refresh_token' => 'encrypted',
            'codex_expires_at' => 'datetime',
            'earliest_refresh_at' => 'datetime',
            'last_refreshed_at' => 'datetime',
        ];
    }
}
