<?php

namespace App\Console\Commands;

use App\Events\FighterAgentCountChanged;
use App\Events\FighterIdled;
use App\Models\SubagentDispatch;
use App\Models\User;
use App\Services\SubagentCountCache;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('fighters:sweep-idle')]
#[Description('Mark fighters idle when their last event is outside the idle window, and drop any subagent slot that has gone quiet past its own (much shorter) window')]
class SweepIdleFighters extends Command
{
    /**
     * Runs both idle sweeps in one process instead of two separate
     * per-minute schedule entries: fighters past game.idle_minutes, and
     * subagent_dispatches rows past game.subagent_idle_seconds — see
     * SubagentCountCache's own docblock for why the latter can't just be a
     * read-time filter (an already-open browser needs the broadcast to
     * shrink its live minion swarm, not just the next page load).
     *
     * @param  SubagentCountCache  $subagentCounts
     * @return int
     */
    public function handle(SubagentCountCache $subagentCounts): int
    {
        $cutoff = now()->subMinutes(config('game.idle_minutes'));

        User::where('last_event_at', '<', $cutoff)
            ->whereNotNull('last_event_at')
            ->chunkById(100, function ($users) {
                foreach ($users as $user) {
                    event(new FighterIdled($user));
                }
            });

        $this->sweepStaleSubagents($subagentCounts);

        return self::SUCCESS;
    }

    /**
     * @param  SubagentCountCache  $subagentCounts
     * @return void
     */
    private function sweepStaleSubagents(SubagentCountCache $subagentCounts): void
    {
        $cutoff = now()->subSeconds(config('game.subagent_idle_seconds'));

        SubagentDispatch::where('last_seen_at', '<', $cutoff)
            ->distinct('user_id')
            ->pluck('user_id')
            ->each(function (int $userId) use ($subagentCounts) {
                $result = $subagentCounts->pruneStale($userId);
                if ($result === null) {
                    return;
                }

                $user = User::find($userId);
                if ($user !== null) {
                    event(new FighterAgentCountChanged($user, $result['count'], $result['seq']));
                }
            });
    }
}
