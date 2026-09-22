<?php

namespace App\Console\Commands;

use App\Models\Boss;
use App\Services\StoneAnnouncer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('boss:announce-stone')]
#[Description('Post to Slack when the alive boss has just collected an Infinity Stone')]
class AnnounceBossStone extends Command
{
    /**
     * Announce the alive boss's latest stone, if any. Reads the boss directly
     * rather than through BossArena::current(), which would spawn one.
     *
     * @param  StoneAnnouncer  $announcer
     * @return int
     */
    public function handle(StoneAnnouncer $announcer): int
    {
        $boss = Boss::where('status', 'alive')->orderByDesc('number')->first();

        if ($announcer->announce($boss)) {
            $this->info("Announced a stone for boss #{$boss->number}.");
        } else {
            $this->info('Nothing to announce.');
        }

        return self::SUCCESS;
    }
}
