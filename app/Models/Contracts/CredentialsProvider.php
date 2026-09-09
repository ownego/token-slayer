<?php

namespace App\Models\Contracts;

use App\Enums\AccountStatus;
use Illuminate\Support\Carbon;

/**
 * Shared read contract for a provider's persistent credential row
 * (`ClaudeCredential`, `CodexCredential`) — the concepts every provider
 * has, letting `Account`'s proxy accessors read the right one through a
 * single `Account::credential()` branch point instead of duplicating a
 * provider check in every accessor. Plan/OAuth-field concepts are
 * deliberately NOT part of this contract — they differ too much in shape
 * between providers to unify meaningfully.
 */
interface CredentialsProvider
{
    /**
     * This credential's connection lifecycle status.
     *
     * @return AccountStatus
     */
    public function credentialStatus(): AccountStatus;

    /**
     * When this credential's account was last successfully probed for
     * quota usage, or null if never.
     *
     * @return ?Carbon
     */
    public function credentialLastProbedAt(): ?Carbon;

    /**
     * The last recorded probe failure message, or null if the last probe
     * succeeded (or none has run yet).
     *
     * @return ?string
     */
    public function credentialProbeError(): ?string;
}
