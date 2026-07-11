<?php

namespace App\Console\Commands;

use App\Services\Accounts\HistoricalMembershipBackfiller;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Manually materialize untracked `account_user` rows for historical event
 * contributors — the one-shot production catch-up for membership that
 * predates ingest-time recording. Thin: delegates to
 * {@see HistoricalMembershipBackfiller}. No schedule entry.
 */
#[Signature('account-membership:backfill')]
#[Description('Materialize untracked account_user rows for historical event contributors')]
class BackfillAccountMembership extends Command
{
    /**
     * Run the backfill and print the count of materialized rows.
     *
     * @param  HistoricalMembershipBackfiller  $backfiller  the historical membership backfiller
     * @return int the command exit code
     */
    public function handle(HistoricalMembershipBackfiller $backfiller): int
    {
        $created = $backfiller->backfill();

        $this->info("Materialized {$created} membership row(s).");

        return self::SUCCESS;
    }
}
