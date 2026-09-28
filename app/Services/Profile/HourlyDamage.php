<?php

namespace App\Services\Profile;

use App\Models\Event;
use App\Models\User;
use App\Services\Recap\RecapWindow;
use Carbon\CarbonImmutable;

/**
 * A player's damage bucketed by local hour, for the fighter sheet's "Last 24
 * hours" bar chart.
 */
class HourlyDamage
{
    /**
     * Exactly 24 hourly buckets, oldest first, ending with the current hour.
     * Bucketed in PHP rather than with a `DATE()`/`strftime` SQL grouping —
     * sqlite returns NULL for those against offset-bearing timestamps, which
     * would silently collapse every bucket into one (see the staging sqlite
     * timestamp trap in the project KB).
     *
     * @param  User  $user
     * @return array<int, array{hour: string, damage: int}>
     */
    public function last24(User $user): array
    {
        $currentHourStart = CarbonImmutable::now()->setTimezone(RecapWindow::TIMEZONE)->startOfHour();
        $windowStart = $currentHourStart->subHours(23);

        $buckets = [];
        for ($i = 0; $i < 24; $i++) {
            $buckets[$windowStart->addHours($i)->format('H:00')] = 0;
        }

        $events = Event::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $windowStart->utc())
            ->get(['tokens', 'created_at']);

        foreach ($events as $event) {
            $hour = $event->created_at->copy()->setTimezone(RecapWindow::TIMEZONE)->format('H:00');
            if (array_key_exists($hour, $buckets)) {
                $buckets[$hour] += (int) $event->tokens;
            }
        }

        return collect($buckets)->map(fn (int $damage, string $hour): array => ['hour' => $hour, 'damage' => $damage])->values()->all();
    }
}
