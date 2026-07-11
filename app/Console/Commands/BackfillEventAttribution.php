<?php

namespace App\Console\Commands;

use App\Services\Attribution\EventAttributionBackfiller;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Attributes unrecognized events (an org beacon present, but no `account_id`)
 * to the account whose `organization_uuid` matches. Thin wrapper over
 * {@see EventAttributionBackfiller}; run manually to catch up history after an
 * account is connected. No schedule entry.
 */
#[Signature('event-attribution:backfill {--org= : Limit the backfill to one organization uuid}')]
#[Description('Attribute unrecognized events (org beacon, no account) to their matching account')]
class BackfillEventAttribution extends Command
{
    /**
     * Run the backfill and print one line per org plus a total.
     *
     * @param  EventAttributionBackfiller  $backfiller  the service that performs the attribution
     * @return int the command exit code
     */
    public function handle(EventAttributionBackfiller $backfiller): int
    {
        $attributed = $backfiller->backfill($this->option('org'));

        $total = 0;
        foreach ($attributed as $org => $count) {
            $this->info("{$org}: attributed {$count} events");
            $total += $count;
        }

        $this->info("total: attributed {$total} events across ".count($attributed).' organizations');

        return self::SUCCESS;
    }
}
