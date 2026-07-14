<?php

namespace App\Services;

use App\Enums\AccountProfileSyncResult;
use App\Exceptions\UsageProbeException;
use App\Models\Account;
use Illuminate\Database\QueryException;

/**
 * Syncs a single account's `plan`, `account_uuid`, and `organization_uuid`
 * from Anthropic's profile API. Extracted from the `accounts:sync-profiles`
 * command so the command stays a thin iterate-and-tally loop and the per-
 * account logic is testable in isolation (the project's thin-entrypoint rule).
 *
 * A profile call failure records a safe {@see UsageProbeException::$reason} in
 * `probe_error` and never flips account status (that is the usage prober's
 * job). Per token-hygiene requirements, `probe_error` never carries raw token
 * material — only the machine-readable reason or a fixed, token-free message.
 */
class AccountProfileSyncer
{
    /**
     * Build the syncer with the profile API client it delegates to.
     *
     * @param  AnthropicOAuthClient  $client  the profile API client
     * @return void
     */
    public function __construct(private AnthropicOAuthClient $client) {}

    /**
     * Fetch the account's profile and apply plan/identity fields. Returns the
     * outcome so the caller can tally without re-inspecting the account.
     *
     * @param  Account  $account  the connected account to sync
     * @return AccountProfileSyncResult synced, mismatched on email, or errored
     */
    public function sync(Account $account): AccountProfileSyncResult
    {
        try {
            $profile = $this->client->profile($account->oauth_access_token);
        } catch (UsageProbeException $exception) {
            $account->probe_error = "profile sync failed: {$exception->reason}";
            $account->save();

            return AccountProfileSyncResult::Errored;
        }

        if ($this->emailMismatches($account, $profile)) {
            $account->probe_error = 'profile email mismatch: '.($profile['account']['email'] ?? '');
            $account->save();

            return AccountProfileSyncResult::Mismatched;
        }

        $this->applyProfile($account, $profile);

        return AccountProfileSyncResult::Synced;
    }

    /**
     * Determine whether the profile's account email differs (case-insensitive)
     * from the stored account email.
     *
     * @param  Account  $account  the account being synced
     * @param  array<string, mixed>  $profile  the decoded profile response
     * @return bool true when the emails differ
     */
    private function emailMismatches(Account $account, array $profile): bool
    {
        $profileEmail = $profile['account']['email'] ?? null;

        if ($profileEmail === null) {
            return false;
        }

        return mb_strtolower($profileEmail) !== mb_strtolower($account->email);
    }

    /**
     * Apply the profile's plan, account_uuid, and organization_uuid to the
     * account and save. `organization_uuid` is unique; a race where another
     * account already claims the same organization uuid is caught and recorded
     * as `probe_error` rather than allowed to bubble up, mirroring
     * {@see AccountResolver::learnOrganizationUuid}.
     *
     * @param  Account  $account  the account being synced
     * @param  array<string, mixed>  $profile  the decoded profile response
     * @return void
     */
    private function applyProfile(Account $account, array $profile): void
    {
        $account->plan = $profile['organization']['rate_limit_tier'] ?? $account->plan;
        $account->account_uuid = $profile['account']['uuid'] ?? $account->account_uuid;
        $account->organization_uuid = $profile['organization']['uuid'] ?? $account->organization_uuid;
        $account->probe_error = null;

        try {
            $account->save();
        } catch (QueryException) {
            // Another account already claims this organization_uuid (unique
            // constraint) — treat as already-learned rather than a failure,
            // and skip the org-uuid write on retry.
            $account->organization_uuid = $account->getOriginal('organization_uuid');
            $account->probe_error = 'org uuid already claimed';
            $account->save();
        }
    }
}
