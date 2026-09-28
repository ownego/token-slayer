<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Display wording for the fighter sheet, matching the approved mockup's own
 * copy: quota states, rank ordinals, refill countdowns, last-seen times and
 * ledger shares. Kept out of the Blade views so the markup stays a straight
 * port of the mockup's.
 */
final class SheetFormat
{
    /**
     * Utilization at or above which a quota reads as "Close".
     *
     * @var int
     */
    public const int WARNING_PERCENT = 70;

    /**
     * Utilization at or above which a quota reads as "Almost out".
     *
     * @var int
     */
    public const int CRITICAL_PERCENT = 95;

    /**
     * When events started carrying input and cache token counts (hook v6,
     * migration 2026_09_11_000000_add_input_and_cache_tokens_to_events_table).
     *
     * @var string
     */
    public const string LEDGER_TRACKED_SINCE = '2026-09-11';

    /**
     * How each period is named after the rank ordinal ("3rd today").
     *
     * @var array<string, string>
     */
    private const array PERIOD_NAMES = [
        'hour' => 'this hour',
        'today' => 'today',
        'week' => 'this week',
        'month' => 'this month',
        'year' => 'this year',
        'all' => 'all time',
        'custom' => 'in range',
    ];

    /**
     * The mockup's three quota states for a utilization percent.
     *
     * @param  int  $percent
     * @return string good, warning or critical
     */
    public static function quotaStatus(int $percent): string
    {
        return match (true) {
            $percent >= self::CRITICAL_PERCENT => 'critical',
            $percent >= self::WARNING_PERCENT => 'warning',
            default => 'good',
        };
    }

    /**
     * The icon and word the mockup pairs with each quota state.
     *
     * @param  string  $status  good, warning or critical
     * @return array{icon: string, label: string}
     */
    public static function statusBadge(string $status): array
    {
        return match ($status) {
            'critical' => ['icon' => '✕', 'label' => 'Almost out'],
            'warning' => ['icon' => '▲', 'label' => 'Close'],
            default => ['icon' => '✓', 'label' => 'OK'],
        };
    }

    /**
     * "3rd today", or an empty string when there is no rank to show.
     *
     * @param  ?int  $rank
     * @param  string  $period  the sheet's period key
     * @return string
     */
    public static function rankLabel(?int $rank, string $period): string
    {
        if ($rank === null) {
            return '';
        }

        return self::ordinal($rank).' '.(self::PERIOD_NAMES[$period] ?? $period);
    }

    /**
     * How the ledger heading names a period ("this week").
     *
     * @param  string  $period  the sheet's period key
     * @return string
     */
    public static function periodName(string $period): string
    {
        return self::PERIOD_NAMES[$period] ?? $period;
    }

    /**
     * "▲ 12% vs yesterday", or an empty string when there is nothing to
     * compare against.
     *
     * @param  ?float  $delta  percent change on the previous stretch
     * @param  string  $period  the sheet's period key
     * @return string
     */
    public static function deltaLabel(?float $delta, string $period): string
    {
        $previous = match ($period) {
            'hour' => 'last hour',
            'today' => 'yesterday',
            'week' => 'last week',
            'month' => 'last month',
            default => null,
        };

        if ($delta === null || $previous === null) {
            return '';
        }

        return ($delta < 0 ? '▼' : '▲').' '.round(abs($delta)).'% vs '.$previous;
    }

    /**
     * Time until `$until`, as its two largest units ("2h 14m", "3d 10h").
     *
     * @param  CarbonInterface  $until
     * @param  ?CarbonInterface  $now
     * @return string
     */
    public static function duration(CarbonInterface $until, ?CarbonInterface $now = null): string
    {
        $seconds = max(0, (int) ($now ?? now())->diffInSeconds($until, false));

        return self::span($seconds, false) ?? '<1m';
    }

    /**
     * Time since `$at` ("2m 5s ago", "3d 10h ago"), or "never".
     *
     * @param  ?CarbonInterface  $at
     * @param  ?CarbonInterface  $now
     * @return string
     */
    public static function ago(?CarbonInterface $at, ?CarbonInterface $now = null): string
    {
        if ($at === null) {
            return 'never';
        }

        $seconds = max(0, (int) $at->diffInSeconds($now ?? now()));

        return (self::span($seconds, true) ?? $seconds.'s').' ago';
    }

    /**
     * Whether a window starting at `$start` (null: all time) reaches back
     * before input and cache tokens were tracked, so those rows undercount.
     *
     * @param  ?CarbonInterface  $start
     * @return bool
     */
    public static function ledgerPartial(?CarbonInterface $start): bool
    {
        return $start === null || $start->lt(self::LEDGER_TRACKED_SINCE);
    }

    /**
     * A ledger share: one decimal below 1%, whole percents above.
     *
     * @param  float  $percent
     * @return string
     */
    public static function percent(float $percent): string
    {
        return $percent < 1 ? number_format($percent, 1).'%' : round($percent).'%';
    }

    /**
     * Two-unit span of a second count; with `$withSeconds`, spans under ten
     * minutes keep their seconds. Null when it is under a minute and seconds
     * are not wanted.
     *
     * @param  int  $seconds
     * @param  bool  $withSeconds
     * @return ?string
     */
    private static function span(int $seconds, bool $withSeconds): ?string
    {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return match (true) {
            $days > 0 => "{$days}d {$hours}h",
            $hours > 0 => "{$hours}h {$minutes}m",
            $minutes > 0 && $withSeconds && $minutes < 10 => "{$minutes}m ".($seconds % 60).'s',
            $minutes > 0 => "{$minutes}m",
            default => null,
        };
    }

    /**
     * 1st, 2nd, 3rd, 4th … 11th, 12th, 13th … 21st.
     *
     * @param  int  $n
     * @return string
     */
    private static function ordinal(int $n): string
    {
        $suffix = in_array($n % 100, [11, 12, 13], true) ? 'th' : match ($n % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };

        return $n.$suffix;
    }
}
