<?php

namespace App\Services\Profile;

use App\Models\Event;
use App\Models\User;
use App\Services\Recap\RecapWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * A player's damage and share of the team's for one period of the sheet.
 */
class DamageByPeriod
{
    /**
     * Damage in a named period.
     *
     * @param  User  $user
     * @param  Period  $period
     * @return array{mine:int, team:int, share:float}
     */
    public function for(User $user, Period $period): array
    {
        return $this->sum($user, fn (Builder $q): Builder => ($start = $period->start(CarbonImmutable::now())) ? $q->where('created_at', '>=', $start) : $q);
    }

    /**
     * Damage over whole local days, both ends inclusive.
     *
     * @param  User  $user
     * @param  CarbonImmutable  $from
     * @param  CarbonImmutable  $to
     * @return array{mine:int, team:int, share:float}
     */
    public function between(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = $from->setTimezone(RecapWindow::TIMEZONE)->startOfDay()->utc();
        $end = $to->setTimezone(RecapWindow::TIMEZONE)->endOfDay()->utc();

        return $this->sum($user, fn (Builder $q): Builder => $q->whereBetween('created_at', [$start, $end]));
    }

    /**
     * Mine, the team's, and the share, over the same window.
     *
     * @param  User  $user
     * @param  callable(Builder): Builder  $window
     * @return array{mine:int, team:int, share:float}
     */
    private function sum(User $user, callable $window): array
    {
        $team = (int) $window(Event::query())->sum('tokens');
        $mine = (int) $window(Event::query()->where('user_id', $user->id))->sum('tokens');

        return ['mine' => $mine, 'team' => $team, 'share' => $team > 0 ? round($mine / $team, 4) : 0.0];
    }
}
