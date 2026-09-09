<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Services\ProviderServiceFactory;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Runs the 5-minute quota-utilization prober across every probeable org
 * account, of either provider, via {@see ProviderServiceFactory::proberFor()}.
 * Each prober already handles expected failures (dead token, transient HTTP
 * errors) by returning null; the try/catch here is a safety net against an
 * unanticipated exception in a single account so it cannot abort the rest of
 * the batch.
 */
#[Signature('accounts:probe')]
#[Description('Probe every probeable org account for its current usage')]
class ProbeAccountUsage extends Command
{
    /**
     * Iterate every {@see Account::scopeProbeable()} (Claude) and
     * {@see Account::scopeCodexProbeable()} (Codex) account, probing each
     * with its provider's prober, and report how many accounts were
     * attempted versus how many produced a recorded snapshot.
     *
     * @param  ProviderServiceFactory  $probers  resolves each account's provider-specific prober
     * @return int the command exit code
     */
    public function handle(ProviderServiceFactory $probers): int
    {
        $probed = 0;
        $recorded = 0;

        $accounts = Account::probeable()->get()->merge(Account::codexProbeable()->get());

        $accounts->each(function (Account $account) use ($probers, &$probed, &$recorded): void {
            $probed++;

            try {
                if ($probers->proberFor($account)->probe($account) !== null) {
                    $recorded++;
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        });

        $this->info("probed {$probed} accounts, {$recorded} snapshots");

        return self::SUCCESS;
    }
}
