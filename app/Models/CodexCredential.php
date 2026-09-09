<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Models\Contracts\CredentialsProvider;
use Database\Factories\CodexCredentialFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One Codex/ChatGPT shared account's persistent credential — populated by
 * `CodexProvisioningService::connectAccount()` (Step A). Per-device
 * provisioning secrets (Step B) are cache-only and never land here — see
 * `CodexProvisioningService::provisionForDevice()`.
 */
#[Hidden(['codex_access_token', 'codex_refresh_token'])]
class CodexCredential extends Model implements CredentialsProvider
{
    /** @use HasFactory<CodexCredentialFactory> */
    use HasFactory;

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
            'last_probed_at' => 'datetime',
        ];
    }

    /**
     * @inheritDoc
     */
    public function credentialStatus(): AccountStatus
    {
        return $this->status;
    }

    /**
     * @inheritDoc
     */
    public function credentialLastProbedAt(): ?Carbon
    {
        return $this->last_probed_at;
    }

    /**
     * @inheritDoc
     */
    public function credentialProbeError(): ?string
    {
        return $this->probe_error;
    }

    /**
     * @return CodexCredentialFactory
     */
    protected static function newFactory(): CodexCredentialFactory
    {
        return CodexCredentialFactory::new();
    }
}
