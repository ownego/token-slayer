<?php

namespace App\Services\Recap;

use App\Models\Boss;
use App\Models\Event;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class RecapDataCollector
{
    public function collect(RecapWindow $window): RecapSnapshot
    {
        $start = $window->start;
        $end = $window->end;

        $bossesSlain = $this->bossesIn($start, $end)->count();
        $totalDamage = (int) $this->eventsIn($start, $end)->sum('tokens');
        $activeFighters = $this->eventsIn($start, $end)->distinct('user_id')->count('user_id');

        $providerSplit = $this->eventsIn($start, $end)
            ->select('provider', DB::raw('SUM(tokens) as damage'))
            ->groupBy('provider')
            ->pluck('damage', 'provider')
            ->map(fn ($damage) => (int) $damage)
            ->all();

        $killsByUser = $this->bossesIn($start, $end)
            ->whereNotNull('killing_blow_user_id')
            ->select('killing_blow_user_id', DB::raw('COUNT(*) as kills'))
            ->groupBy('killing_blow_user_id')
            ->pluck('kills', 'killing_blow_user_id');

        $leaderboard = $this->eventsIn($start, $end)
            ->select('user_id', DB::raw('SUM(tokens) as damage'))
            ->groupBy('user_id')
            ->orderByDesc('damage')
            ->limit($window->topN())
            ->with('user:id,name,slack_handle,display_name')
            ->get()
            ->map(fn (Event $row) => new RecapFighter(
                user: $row->user,
                damage: (int) $row->damage,
                kills: (int) ($killsByUser[$row->user_id] ?? 0),
            ));

        return new RecapSnapshot(
            window: $window,
            bossesSlain: $bossesSlain,
            totalDamage: $totalDamage,
            activeFighters: $activeFighters,
            leaderboard: $leaderboard,
            providerSplit: $providerSplit,
        );
    }

    private function eventsIn(CarbonInterface $start, CarbonInterface $end): Builder
    {
        return Event::query()
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end);
    }

    private function bossesIn(CarbonInterface $start, CarbonInterface $end): Builder
    {
        return Boss::query()
            ->where('defeated_at', '>=', $start)
            ->where('defeated_at', '<', $end);
    }
}
