<?php

namespace App\Console\Commands;

use App\Events\FighterAgentCountChanged;
use App\Models\SubagentDispatch;
use App\Models\User;
use App\Services\SubagentCountCache;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('subagents:sweep-idle')]
#[Description('Drop a tracked subagent once nothing has been heard from it for game.subagent_idle_seconds')]
class SweepIdleSubagents extends Command
{
    /**
     * @param  SubagentCountCache  $subagentCounts
     * @return int
     */
    public function handle(SubagentCountCache $subagentCounts): int
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

        return self::SUCCESS;
    }
}
