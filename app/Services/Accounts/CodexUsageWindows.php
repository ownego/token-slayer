<?php

namespace App\Services\Accounts;

use App\Services\CodexUsageProber;
use Illuminate\Support\Carbon;

/**
 * The rate-limit windows inside a stored Codex usage response.
 *
 * Codex does not report the 5-hour and 7-day pair Claude does. A free-tier
 * account comes back with a single 30-day cap and no secondary window at all
 * (verified against a live account on 2026-09-07), while a paid one reports
 * the 5h/7d pair. The window's own `limit_window_seconds` is the only
 * trustworthy statement of what it measures, so the label is derived from it
 * rather than assumed.
 *
 * That assumption is what {@see CodexUsageProber} used to make:
 * a window matching neither known duration was filed into the 5-hour column
 * anyway, so a month's usage appeared under an hour's name with nothing on
 * screen to say otherwise.
 */
final class CodexUsageWindows
{
    /**
     * Durations worth naming, in seconds, mapped to the label the rest of the
     * panel already uses for them.
     *
     * @var array<int, string>
     */
    private const array NAMED = [
        18000 => '5H',
        86400 => '24H',
        604800 => '7D',
        2592000 => '30D',
    ];

    /**
     * The windows a Codex usage response reports, primary first.
     *
     * Order is the API's own: the primary window is the one an account hits
     * first, so it is the one worth reading first.
     *
     * @param  mixed  $raw  the stored probe response, whatever shape it is in
     * @return array<int, array{label: string, percent: int, resets_at: ?Carbon}>
     */
    public static function from(mixed $raw): array
    {
        $rateLimit = is_array($raw) ? ($raw['rate_limit'] ?? null) : null;

        if (! is_array($rateLimit)) {
            return [];
        }

        $windows = [];

        foreach (['primary_window', 'secondary_window'] as $key) {
            $window = $rateLimit[$key] ?? null;

            if (! is_array($window) || ! is_numeric($window['used_percent'] ?? null)) {
                continue;
            }

            $resetsAt = $window['reset_at'] ?? null;

            $windows[] = [
                'label' => self::label($window['limit_window_seconds'] ?? null),
                'percent' => (int) round((float) $window['used_percent']),
                'resets_at' => is_numeric($resetsAt) ? Carbon::createFromTimestamp((int) $resetsAt) : null,
            ];
        }

        return $windows;
    }

    /**
     * A window's label, derived from how long it runs.
     *
     * An unfamiliar duration is rendered in hours rather than dropped or
     * forced into a named bucket: it is still a real limit the account spends
     * against, and stating its actual length is the only honest answer when
     * the app has no name for it.
     *
     * @param  mixed  $seconds  the window's `limit_window_seconds`
     * @return string
     */
    private static function label(mixed $seconds): string
    {
        if (! is_numeric($seconds)) {
            return '?';
        }

        $seconds = (int) $seconds;

        if (isset(self::NAMED[$seconds])) {
            return self::NAMED[$seconds];
        }

        $hours = $seconds / 3600;

        return rtrim(rtrim(number_format($hours, 1), '0'), '.').'H';
    }
}
