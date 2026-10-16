<?php

namespace App\Console\Commands;

use App\Services\CustomCharacter\PruneStaleCustomCharacterDrafts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('custom-characters:prune-drafts')]
#[Description('Delete custom fighter drafts nobody used within the time limit, with their staged files')]
class PruneCustomCharacterDrafts extends Command
{
    /**
     * Removes the stale drafts and reports how many went.
     *
     * @param  PruneStaleCustomCharacterDrafts  $prune  removes the drafts
     * @return int the command exit code
     */
    public function handle(PruneStaleCustomCharacterDrafts $prune): int
    {
        $removed = $prune->execute();

        $this->info("{$removed} stale drafts removed");

        return self::SUCCESS;
    }
}
