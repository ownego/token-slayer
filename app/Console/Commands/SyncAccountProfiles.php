<?php

namespace App\Console\Commands;

use App\Enums\AccountProfileSyncResult;
use App\Enums\AccountStatus;
use App\Models\Account;
use App\Services\AccountProfileSyncer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs the daily profile sync across every connected account, delegating the
 * per-account work to {@see AccountProfileSyncer} and tallying the outcomes.
 * The command stays thin (resolve accounts, iterate, delegate, report) per the
 * project's thin-entrypoint rule.
 */
#[Signature('accounts:sync-profiles')]
#[Description("Sync each connected account's plan and identity fields from Anthropic's profile API")]
class SyncAccountProfiles extends Command
{
    /**
     * Iterate every connected account (not disabled, holding an access token),
     * sync its profile via the syncer, and report how many accounts were
     * synced cleanly, mismatched on email, or errored.
     *
     * @param  AccountProfileSyncer  $syncer  the per-account profile syncer
     * @return int the command exit code
     */
    public function handle(AccountProfileSyncer $syncer): int
    {
        $synced = 0;
        $mismatched = 0;
        $errors = 0;

        Account::query()
            ->where('status', '!=', AccountStatus::Disabled->value)
            // Skip NeedsReauth accounts: their access token is known-stale, a
            // profile call would 401, and clobbering their invalid_grant
            // probe_error would erase the theft/re-auth signal the prober set.
            ->where('status', '!=', AccountStatus::NeedsReauth->value)
            ->whereNotNull('oauth_access_token')
            ->get()
            ->each(function (Account $account) use ($syncer, &$synced, &$mismatched, &$errors): void {
                match ($syncer->sync($account)) {
                    AccountProfileSyncResult::Synced => $synced++,
                    AccountProfileSyncResult::Mismatched => $mismatched++,
                    AccountProfileSyncResult::Errored => $errors++,
                };
            });

        $this->info("synced {$synced}, mismatched {$mismatched}, errors {$errors}");

        return self::SUCCESS;
    }
}
