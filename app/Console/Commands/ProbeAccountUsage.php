<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Services\UsageProber;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Runs the 5-minute quota-utilization prober across every probeable org
 * account. {@see UsageProber::probe} already handles expected failures
 * (dead refresh token, transient HTTP errors) by returning null; the
 * try/catch here is a safety net against an unanticipated exception in a
 * single account so it cannot abort the rest of the batch.
 */
#[Signature('accounts:probe')]
#[Description('Probe every probeable org account for its current Anthropic usage')]
class ProbeAccountUsage extends Command
{
    /**
     * Iterate every {@see Account::scopeProbeable()} account, probing each
     * in turn, and report how many accounts were attempted versus how many
     * produced a recorded snapshot.
     *
     * @param  UsageProber  $prober  the service that probes a single account
     * @return int the command exit code
     */
    public function handle(UsageProber $prober): int
    {
        $probed = 0;
        $recorded = 0;

        Account::probeable()->get()->each(function (Account $account) use ($prober, &$probed, &$recorded): void {
            $probed++;

            try {
                if ($prober->probe($account) !== null) {
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
