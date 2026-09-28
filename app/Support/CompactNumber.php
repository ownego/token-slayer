<?php

namespace App\Support;

/**
 * Short damage/token figures for the fighter sheet ("1.26M", "780K") —
 * the PHP twin of the approved mockup's own fmt(), so server-rendered and
 * live-updated numbers read the same.
 */
final class CompactNumber
{
    /**
     * Formats a count: billions and millions to at most two decimals with
     * trailing zeros dropped, thousands rounded to a whole K, smaller counts
     * as-is.
     *
     * @param  int|float  $n
     * @return string
     */
    public static function format(int|float $n): string
    {
        return match (true) {
            $n >= 1e9 => self::trim($n / 1e9).'B',
            $n >= 1e6 => self::trim($n / 1e6).'M',
            $n >= 1e3 => round($n / 1e3).'K',
            default => (string) round($n),
        };
    }

    /**
     * Two decimals, trailing zeros (and a bare point) dropped.
     *
     * @param  float  $n
     * @return string
     */
    private static function trim(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }
}
