<?php

namespace App\Services\Profile;

use App\Models\Event;
use App\Models\User;
use App\Services\Recap\RecapWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The rest of the Fighter sheet's damage panel beyond {@see DamageByPeriod}:
 * the fighter's rank for the window, the window's token breakdown (the
 * ledger), and the change against the same stretch of the previous window.
 * Everything is summed fresh from `events`.
 */
class PeriodStats
{
    /**
     * Rank, token breakdown and delta for a named period.
     *
     * @param  User  $user
     * @param  Period  $period
     * @return array{rank: ?int, tokens: array{out: int, in: int, cw: int, cr: int}, delta: ?float}
     */
    public function for(User $user, Period $period): array
    {
        $now = CarbonImmutable::now();
        $start = $period->start($now);

        return [
            'rank' => $this->rank($user, $start, $now),
            'tokens' => $this->tokens($user, $start, $now),
            'delta' => $this->delta($user, $period, $start, $now),
        ];
    }

    /**
     * Rank and token breakdown over whole local days, both ends inclusive; a
     * custom range has no "previous window", so no delta.
     *
     * @param  User  $user
     * @param  CarbonImmutable  $from
     * @param  CarbonImmutable  $to
     * @return array{rank: ?int, tokens: array{out: int, in: int, cw: int, cr: int}, delta: ?float}
     */
    public function between(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = $from->setTimezone(RecapWindow::TIMEZONE)->startOfDay()->utc();
        $end = $to->setTimezone(RecapWindow::TIMEZONE)->endOfDay()->utc();

        return [
            'rank' => $this->rank($user, $start, $end),
            'tokens' => $this->tokens($user, $start, $end),
            'delta' => null,
        ];
    }

    /**
     * The fighter's 1-based place by damage in the window, or null when they
     * dealt none — having no hits is not last place.
     *
     * @param  User  $user
     * @param  CarbonImmutable|null  $start
     * @param  CarbonImmutable  $end
     * @return int|null
     */
    private function rank(User $user, ?CarbonImmutable $start, CarbonImmutable $end): ?int
    {
        $mine = (int) $this->window(Event::query()->where('user_id', $user->id), $start, $end)->sum('tokens');

        if ($mine === 0) {
            return null;
        }

        $ahead = $this->window(Event::query(), $start, $end)
            ->selectRaw('user_id, SUM(tokens) as damage')
            ->groupBy('user_id')
            ->havingRaw('SUM(tokens) > ?', [$mine])
            ->get()
            ->count();

        return $ahead + 1;
    }

    /**
     * Output (damage), input, cache-written and cache-read token totals.
     *
     * @param  User  $user
     * @param  CarbonImmutable|null  $start
     * @param  CarbonImmutable  $end
     * @return array{out: int, in: int, cw: int, cr: int}
     */
    private function tokens(User $user, ?CarbonImmutable $start, CarbonImmutable $end): array
    {
        $row = $this->window(Event::query()->where('user_id', $user->id), $start, $end)
            ->selectRaw('COALESCE(SUM(tokens), 0) as out_t, COALESCE(SUM(input_tokens), 0) as in_t, COALESCE(SUM(cache_creation_input_tokens), 0) as cw_t, COALESCE(SUM(cache_read_input_tokens), 0) as cr_t')
            ->first();

        return ['out' => (int) $row->out_t, 'in' => (int) $row->in_t, 'cw' => (int) $row->cw_t, 'cr' => (int) $row->cr_t];
    }

    /**
     * Percent change of this window so far against the same stretch of the
     * previous one (today until now vs. yesterday until this time), or null
     * for year/all-time or when the previous stretch had no damage.
     *
     * @param  User  $user
     * @param  Period  $period
     * @param  CarbonImmutable|null  $start
     * @param  CarbonImmutable  $now
     * @return float|null
     */
    private function delta(User $user, Period $period, ?CarbonImmutable $start, CarbonImmutable $now): ?float
    {
        $shift = match ($period) {
            Period::Hour => fn (CarbonImmutable $t): CarbonImmutable => $t->subHour(),
            Period::Today => fn (CarbonImmutable $t): CarbonImmutable => $t->subDay(),
            Period::Week => fn (CarbonImmutable $t): CarbonImmutable => $t->subWeek(),
            Period::Month => fn (CarbonImmutable $t): CarbonImmutable => $t->subMonthNoOverflow(),
            default => null,
        };

        if ($shift === null || $start === null) {
            return null;
        }

        $mine = fn (CarbonImmutable $from, CarbonImmutable $to): int => (int) $this->window(Event::query()->where('user_id', $user->id), $from, $to)->sum('tokens');
        $previous = $mine($shift($start), $shift($now));

        if ($previous === 0) {
            return null;
        }

        return round(($mine($start, $now) - $previous) / $previous * 100, 1);
    }

    /**
     * Narrows a query to [start, end], or to everything up to end when the
     * window has no start (all time).
     *
     * @param  Builder  $query
     * @param  CarbonImmutable|null  $start
     * @param  CarbonImmutable  $end
     * @return Builder
     */
    private function window(Builder $query, ?CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return $start === null
            ? $query->where('created_at', '<=', $end)
            : $query->whereBetween('created_at', [$start, $end]);
    }
}
