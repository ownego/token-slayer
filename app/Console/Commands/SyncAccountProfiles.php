<?php

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Exceptions\UsageProbeException;
use App\Models\Account;
use App\Services\AccountResolver;
use App\Services\AnthropicOAuthClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

/**
 * Runs the daily profile sync across every connected account: refreshes
 * `plan`, `account_uuid`, and `organization_uuid` from Anthropic's profile
 * API. A profile call failure records a safe {@see UsageProbeException::$reason}
 * in `probe_error` and moves on; it never flips account status (that is the
 * usage prober's job, not this command's).
 *
 * Per token-hygiene requirements, `probe_error` never carries raw token
 * material — only the exception's machine-readable reason or a fixed,
 * token-free description of the mismatch/collision.
 */
#[Signature('accounts:sync-profiles')]
#[Description("Sync each connected account's plan and identity fields from Anthropic's profile API")]
class SyncAccountProfiles extends Command
{
    /**
     * Iterate every connected account (not disabled, holding an access
     * token), sync its profile, and report how many accounts were synced
     * cleanly, mismatched on email, or errored.
     *
     * @param  AnthropicOAuthClient  $client  the profile API client
     * @return int the command exit code
     */
    public function handle(AnthropicOAuthClient $client): int
    {
        $synced = 0;
        $mismatched = 0;
        $errors = 0;

        Account::query()
            ->where('status', '!=', AccountStatus::Disabled->value)
            ->whereNotNull('oauth_access_token')
            ->get()
            ->each(function (Account $account) use ($client, &$synced, &$mismatched, &$errors): void {
                try {
                    $profile = $client->profile($account->oauth_access_token);
                } catch (UsageProbeException $exception) {
                    $errors++;
                    $account->probe_error = "profile sync failed: {$exception->reason}";
                    $account->save();

                    return;
                }

                if ($this->emailMismatches($account, $profile)) {
                    $mismatched++;
                    $account->probe_error = 'profile email mismatch: '.($profile['account']['email'] ?? '');
                    $account->save();

                    return;
                }

                $synced++;
                $this->applyProfile($account, $profile);
            });

        $this->info("synced {$synced}, mismatched {$mismatched}, errors {$errors}");

        return self::SUCCESS;
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
     * account already claims the same organization uuid is caught and
     * recorded as `probe_error` rather than allowed to bubble up, mirroring
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
