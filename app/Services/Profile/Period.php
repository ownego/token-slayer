<?php

namespace App\Services\Profile;

use App\Services\Recap\RecapWindow;
use Carbon\CarbonImmutable;

/**
 * The windows the Fighter sheet's period tabs read damage over.
 */
enum Period: string
{
    case Hour = 'hour';
    case Today = 'today';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';
    case All = 'all';

    /**
     * Where this window starts, as a UTC instant; calendar windows start at
     * the display timezone's midnight / Monday / 1st, so "today" means today
     * for the team, not the last 24 hours.
     *
     * @param  CarbonImmutable  $now
     * @return CarbonImmutable|null null for all time
     */
    public function start(CarbonImmutable $now): ?CarbonImmutable
    {
        $local = $now->setTimezone(RecapWindow::TIMEZONE);

        return match ($this) {
            self::Hour => $now->subHour(),
            self::Today => $local->startOfDay()->utc(),
            self::Week => $local->startOfWeek()->utc(),
            self::Month => $local->startOfMonth()->utc(),
            self::Year => $local->startOfYear()->utc(),
            self::All => null,
        };
    }
}
