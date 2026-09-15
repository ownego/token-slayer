<?php

namespace App\Console\Commands;

use App\Services\Provisioning\GrantSessionExpiryBackfiller;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Stamps an estimated `session_expires_at` onto every live grant that
 * predates the column and has none on record. Thin wrapper over
 * {@see GrantSessionExpiryBackfiller}; run once after the migration ships to
 * catch up history. No schedule entry — going forward every new/reissued
 * grant and every confirmed setup records a real deadline on its own.
 */
#[Signature('grant-session-expiry:backfill')]
#[Description('Estimate a session deadline for live grants that have none recorded')]
class BackfillGrantSessionExpiry extends Command
{
    /**
     * Run the backfill and print how many grants were touched.
     *
     * @param  GrantSessionExpiryBackfiller  $backfiller  the service that performs the backfill
     * @return int the command exit code
     */
    public function handle(GrantSessionExpiryBackfiller $backfiller): int
    {
        $count = $backfiller->backfill();

        $this->info("backfilled an estimated session deadline onto {$count} grants");

        return self::SUCCESS;
    }
}
