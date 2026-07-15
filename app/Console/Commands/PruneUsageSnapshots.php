<?php

namespace App\Console\Commands;

use App\Models\AccountUsageSnapshot;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Prunes append-only quota-utilization snapshots older than the 30-day
 * retention window. Snapshots are captured every 5 minutes per account, so
 * this keeps the table bounded without affecting any derived aggregate —
 * nothing reads past the retention window.
 */
#[Signature('accounts:prune-usage-snapshots')]
#[Description('Delete account usage snapshots older than 30 days')]
class PruneUsageSnapshots extends Command
{
    /**
     * Retention window, in days, for {@see AccountUsageSnapshot} rows.
     *
     * @var int
     */
    private const int RETENTION_DAYS = 30;

    /**
     * Delete every snapshot whose `created_at` falls outside the retention
     * window and report how many rows were removed.
     *
     * @return int the command exit code
     */
    public function handle(): int
    {
        $deleted = AccountUsageSnapshot::where('created_at', '<', now()->subDays(self::RETENTION_DAYS))->delete();

        $this->info("pruned {$deleted} usage snapshots");

        return self::SUCCESS;
    }
}
