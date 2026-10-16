<?php

namespace App\Console\Commands;

use App\Services\CustomCharacter\PruneOrphanCustomCharacterFiles;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('custom-characters:prune-orphans')]
#[Description('Delete old custom fighter strip folders with no database reference')]
class SweepOrphanCustomCharacterFiles extends Command
{
    /**
     * Sweeps orphan files and reports the number removed.
     *
     * @param  PruneOrphanCustomCharacterFiles  $prune  orphan cleanup action
     * @return int the command exit code
     */
    public function handle(PruneOrphanCustomCharacterFiles $prune): int
    {
        $removed = $prune->execute();

        $this->info("{$removed} orphan folders removed");

        return self::SUCCESS;
    }
}
