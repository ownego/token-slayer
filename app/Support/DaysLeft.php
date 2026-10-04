<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Formats a session deadline as whole days left or overdue — the one wording
 * every reissue surface (Members modal, Expiring page, Reserve tab) shares.
 */
final class DaysLeft
{
    /**
     * The short label for `$deadline`: `—`, `today`, `{n}d left` or
     * `{n}d overdue`, prefixed `~` when the deadline is only an estimate.
     *
     * @param  Carbon|null  $deadline  the session deadline, or null when unknown
     * @param  bool  $estimated  whether the deadline is a backfilled guess
     * @return string
     */
    public static function label(?Carbon $deadline, bool $estimated = false): string
    {
        if ($deadline === null) {
            return '—';
        }

        // Signed and truncated toward zero: anything within a day either
        // side reads as "today", 5.5 days reads as 5.
        $days = (int) now()->diffInDays($deadline, false);
        $label = match (true) {
            $days === 0 => 'today',
            $days > 0 => "{$days}d left",
            default => abs($days).'d overdue',
        };

        return $estimated ? "~{$label}" : $label;
    }

    /**
     * Whether `$deadline` has already passed.
     *
     * @param  Carbon|null  $deadline  the session deadline, or null when unknown
     * @return bool
     */
    public static function isOverdue(?Carbon $deadline): bool
    {
        return $deadline !== null && $deadline->isPast();
    }
}
