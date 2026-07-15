<?php

namespace App\Services\Analytics;

use Illuminate\Support\Carbon;

/**
 * Immutable snapshot of the analytics page's shared filter: the time range,
 * an optional account/provider/user narrowing, and the derived time bucket.
 * Built once per request from the Filament filter form and passed into every
 * `UsageAnalytics` query so the whole page reads from one consistent filter.
 */
final class UsageFilters
{
    /**
     * Largest range (in days) any query will scan, to bound result size and
     * protect the database from an unbounded custom range.
     *
     * @var int
     */
    public const int MAX_RANGE_DAYS = 90;

    /**
     * Range length at or below which the time bucket is hourly rather than
     * daily, in hours.
     *
     * @var int
     */
    public const int HOURLY_BUCKET_MAX_HOURS = 48;

    /**
     * The time bucket granularity for series queries: `'hour'` or `'day'`.
     *
     * @var string
     */
    public readonly string $bucket;

    /**
     * Build an immutable filter snapshot and derive the time bucket from the range.
     *
     * @param  Carbon  $from  inclusive start of the range
     * @param  Carbon  $to  inclusive end of the range
     * @param  ?int  $accountId  narrow to one account, or null for all
     * @param  ?string  $provider  narrow to one provider, or null for all
     * @param  ?int  $userId  narrow to one user, or null for all
     */
    public function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly ?int $accountId,
        public readonly ?string $provider,
        public readonly ?int $userId,
    ) {
        $this->bucket = $from->diffInSeconds($to) <= self::HOURLY_BUCKET_MAX_HOURS * 3600 ? 'hour' : 'day';
    }

    /**
     * Build filters from the Filament page filter form's raw array. `range`
     * is one of `24h | 7d | 30d | custom`; `custom` reads `from`/`to` dates
     * and is clamped to {@see self::MAX_RANGE_DAYS}. Missing selections mean
     * "all".
     *
     * @param  array<string, mixed>  $filters  raw values from the filter form
     * @return self
     */
    public static function fromPageFilters(array $filters): self
    {
        $to = now();
        $from = match ($filters['range'] ?? '7d') {
            '24h' => now()->subDay(),
            '30d' => now()->subDays(30),
            'custom' => Carbon::parse($filters['from'] ?? now()->subDays(7)->toDateString())->startOfDay(),
            default => now()->subDays(7),
        };

        if (($filters['range'] ?? null) === 'custom') {
            $to = Carbon::parse($filters['to'] ?? now()->toDateString())->endOfDay();
        }

        $floor = $to->copy()->subDays(self::MAX_RANGE_DAYS);
        if ($from->lessThan($floor)) {
            $from = $floor;
        }

        return new self(
            $from,
            $to,
            self::intOrNull($filters['account_id'] ?? null),
            ($filters['provider'] ?? null) ?: null,
            self::intOrNull($filters['user_id'] ?? null),
        );
    }

    /**
     * Coerce a raw filter value to a positive int, or null when it is absent
     * or blank. A Filament select cleared back to its placeholder submits an
     * empty string, which must mean "no filter" (show all), not id 0.
     *
     * @param  mixed  $value  the raw filter value
     * @return ?int the int id, or null when absent/blank
     */
    private static function intOrNull(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }
}
