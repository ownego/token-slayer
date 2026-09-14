<?php

namespace App\Services\Accounts;

use Illuminate\Support\Carbon;

/**
 * How far back the rebalance analysis looks. Chosen by the admin on the
 * Rebalance page rather than fixed in config: a week answers "who is heavy
 * right now", a month answers "who is heavy in general", and all-time
 * answers "what has this fleet ever looked like". Every capacity and
 * demand figure in a single recommendation run shares one of these, so the
 * numbers on a row are always comparable with each other.
 */
final readonly class RebalanceWindow
{
    /**
     * @param  int|null  $days  how many days back to read, or null for all time
     */
    private function __construct(public ?int $days) {}

    /**
     * A window covering the trailing `$days` days.
     *
     * @param  int  $days  how many days back to read
     * @return self
     */
    public static function days(int $days): self
    {
        return new self($days);
    }

    /**
     * A window with no lower bound — every event and snapshot on record.
     *
     * @return self
     */
    public static function all(): self
    {
        return new self(null);
    }

    /**
     * Build from the page filter's string value, falling back to the
     * configured default when the value is absent or unrecognised.
     *
     * @param  string|null  $value  `'week'`, `'month'`, `'all'`, or null
     * @return self
     */
    public static function fromFilter(?string $value): self
    {
        return match ($value) {
            'week' => self::days(7),
            'month' => self::days(30),
            'all' => self::all(),
            default => self::days((int) config('token_slayer.rebalance.trend_window_days')),
        };
    }

    /**
     * The earliest moment this window includes, or null when unbounded.
     *
     * @return Carbon|null
     */
    public function since(): ?Carbon
    {
        return $this->days === null ? null : now()->subDays($this->days);
    }

    /**
     * How many days of data this window spans, using `$fallback` when it is
     * unbounded — for rates that need to divide by a day count.
     *
     * @param  int  $fallback  day count to assume for an all-time window
     * @return int
     */
    public function daysOr(int $fallback): int
    {
        return $this->days ?? $fallback;
    }

    /**
     * Short human label for the UI and for explaining a figure's basis.
     *
     * @return string
     */
    public function label(): string
    {
        return $this->days === null ? 'all time' : "last {$this->days} days";
    }
}
