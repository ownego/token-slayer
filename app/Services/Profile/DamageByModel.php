<?php

namespace App\Services\Profile;

use App\Enums\ModelFamily;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * A player's damage grouped by model, for the fighter sheet's "By model" tab.
 */
class DamageByModel
{
    /**
     * Damage grouped per distinct model the player hit with in a period,
     * highest first. A null `events.model` (a hit predating per-event model
     * tracking) is folded into one `'unknown'` row.
     *
     * @param  User  $user
     * @param  Period  $period
     * @return array<int, array{model: string, family: ?string, damage: int, share: float}>
     */
    public function for(User $user, Period $period): array
    {
        $query = Event::query()->where('user_id', $user->id);
        if ($start = $period->start(CarbonImmutable::now())) {
            $query->where('created_at', '>=', $start);
        }

        $rows = $query
            ->selectRaw('COALESCE(model, ?) as model, SUM(tokens) as damage', ['unknown'])
            ->groupByRaw('COALESCE(model, ?)', ['unknown'])
            ->orderByDesc('damage')
            ->get();

        $total = (int) $rows->sum('damage');

        return $rows->map(fn ($row) => [
            'model' => $row->model,
            'family' => ModelFamily::fromModelId($row->model === 'unknown' ? null : $row->model)?->value,
            'damage' => (int) $row->damage,
            'share' => $total > 0 ? round(((int) $row->damage) / $total, 4) : 0.0,
        ])->all();
    }
}
