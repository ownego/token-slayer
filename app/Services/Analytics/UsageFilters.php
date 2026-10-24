<?php

namespace App\Services\Analytics;

use App\Models\Event;
use App\Services\Analytics\Concerns\ScopesEventsByFilters;
use App\Services\DamageService;
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
     * Filter value standing for "events with no recorded model". `unknown` is
     * a display label for `NULL`, never a stored value, so matching it with an
     * equality comparison would return no rows at all — which matters because
     * it is the most populated bucket the day model tracking ships.
     *
     * Declared on this class rather than on {@see ScopesEventsByFilters}
     * because PHP forbids reading a trait constant through the trait name, and
     * the filter form does not `use` that trait.
     *
     * @var string
     */
    public const string UNKNOWN_MODEL = 'unknown';

    /**
     * Largest range (in days) any query will scan, to bound result size and
     * protect the database from an unbounded custom range. Not applied to
     * the `all` and `year` ranges, which are intentionally long.
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
     * Range length at or below which the time bucket is daily rather than
     * weekly, in days.
     *
     * @var int
     */
    public const int DAILY_BUCKET_MAX_DAYS = 90;

    /**
     * Range length at or below which the time bucket is weekly rather than
     * monthly, in days (roughly two years).
     *
     * @var int
     */
    public const int WEEKLY_BUCKET_MAX_DAYS = 730;

    /**
     * Years to look back for the `all` range's `from` when there are no
     * events yet to derive an earliest date from.
     *
     * @var int
     */
    public const int ALL_TIME_FALLBACK_YEARS = 5;

    /**
     * Micro-dollars in one dollar; event costs are stored in micro-dollars.
     *
     * @var int
     */
    private const int MICRO_USD_PER_USD = 1_000_000;

    /**
     * The time bucket granularity for series queries: `'hour'`, `'day'`,
     * `'week'`, or `'month'`.
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
     * @param  ?string  $model  narrow to one raw model id, the sentinel
     *                          {@see self::UNKNOWN_MODEL} for events with no
     *                          recorded model, or null for all
     * @param  string  $tokenMode  `'output'` (default, what damage is dealt
     *                             from), `'total'` (output + input + cache),
     *                             or `'cost'` (list-price USD, stored as
     *                             micro-dollars per event)
     */
    public function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly ?int $accountId,
        public readonly ?string $provider,
        public readonly ?int $userId,
        public readonly ?string $model = null,
        public readonly string $tokenMode = 'output',
    ) {
        $seconds = $from->diffInSeconds($to);

        $this->bucket = match (true) {
            $seconds <= self::HOURLY_BUCKET_MAX_HOURS * 3600 => 'hour',
            $seconds <= self::DAILY_BUCKET_MAX_DAYS * 86400 => 'day',
            $seconds <= self::WEEKLY_BUCKET_MAX_DAYS * 86400 => 'week',
            default => 'month',
        };
    }

    /**
     * Build filters from the Filament page filter form's raw array. `range`
     * is one of `all | today | week | month | year | 24h | 7d | 30d | custom`;
     * `custom` reads `from`/`to` dates. All ranges except `all` and `year`
     * are clamped to {@see self::MAX_RANGE_DAYS}. Missing selections mean
     * "all" (7d fallback for the range, unfiltered for account/provider/user).
     *
     * @param  array<string, mixed>  $filters  raw values from the filter form
     * @return self
     */
    public static function fromPageFilters(array $filters): self
    {
        $to = now();
        $range = $filters['range'] ?? '7d';

        $from = match ($range) {
            '24h' => now()->subDay(),
            'today' => now()->startOfDay(),
            'week' => now()->startOfWeek(),
            'month' => now()->startOfMonth(),
            'year' => now()->startOfYear(),
            '30d' => now()->subDays(30),
            'all' => self::allTimeFrom(),
            'custom' => Carbon::parse($filters['from'] ?? now()->subDays(7)->toDateString())->startOfDay(),
            default => now()->subDays(7),
        };

        if ($range === 'custom') {
            $to = Carbon::parse($filters['to'] ?? now()->toDateString())->endOfDay();
        }

        if (! in_array($range, ['all', 'year'], true)) {
            $floor = $to->copy()->subDays(self::MAX_RANGE_DAYS);
            if ($from->lessThan($floor)) {
                $from = $floor;
            }
        }

        return new self(
            $from,
            $to,
            self::intOrNull($filters['account_id'] ?? null),
            ($filters['provider'] ?? null) ?: null,
            self::intOrNull($filters['user_id'] ?? null),
            ($filters['model'] ?? null) ?: null,
            in_array($filters['token_mode'] ?? null, ['total', 'cost'], true) ? $filters['token_mode'] : 'output',
        );
    }

    /**
     * The SQL fragment every analytics query sums/orders by, chosen by
     * {@see self::$tokenMode}. `output` reads the plain column (identical to
     * pre-filter behavior, and to what {@see DamageService} deals damage
     * from); `total` adds input and cache tokens back in for analytics only,
     * except a Codex event's cache reads, which Codex already counts inside
     * `input_tokens`; `cost` sums the stored micro-dollar cost, an unpriced
     * event counting as zero. Never itself passed to a query builder's
     * `sum()`/`orderBy()` (those would try to quote it as a single
     * identifier) -- callers wrap it in `DB::raw()` or interpolate it into a
     * `selectRaw`/`orderByRaw` string.
     *
     * @return string
     */
    public function tokenColumnExpression(): string
    {
        return match ($this->tokenMode) {
            'total' => "(events.tokens + events.input_tokens + events.cache_creation_input_tokens + CASE WHEN events.provider = 'codex' THEN 0 ELSE events.cache_read_input_tokens END)",
            'cost' => 'COALESCE(events.cost_micro_usd, 0)',
            default => 'events.tokens',
        };
    }

    /**
     * Whether amounts are micro-dollars rather than token counts.
     *
     * @return bool
     */
    public function isCostMode(): bool
    {
        return $this->tokenMode === 'cost';
    }

    /**
     * What the summed amount is called on charts.
     *
     * @return string
     */
    public function amountLabel(): string
    {
        return $this->isCostMode() ? 'Cost (USD)' : 'Tokens';
    }

    /**
     * A summed amount as a chart value: dollars rounded to cents in cost
     * mode, the token count otherwise.
     *
     * @param  int  $amount  a value summed by {@see self::tokenColumnExpression()}
     * @return int|float
     */
    public function displayAmount(int $amount): int|float
    {
        return $this->isCostMode() ? round($amount / self::MICRO_USD_PER_USD, 2) : $amount;
    }

    /**
     * A summed amount as text: `$1,234.56` in cost mode, `1,234` otherwise.
     *
     * @param  int  $amount  a value summed by {@see self::tokenColumnExpression()}
     * @return string
     */
    public function formatAmount(int $amount): string
    {
        return $this->isCostMode()
            ? '$'.number_format($amount / self::MICRO_USD_PER_USD, 2)
            : number_format($amount);
    }

    /**
     * Start of the `all` range: the earliest recorded event, or a far-past
     * fallback ({@see self::ALL_TIME_FALLBACK_YEARS} years back) when no
     * events exist yet.
     *
     * @return Carbon the earliest event's timestamp, or the fallback date
     */
    private static function allTimeFrom(): Carbon
    {
        $earliest = Event::min('created_at');

        return $earliest !== null
            ? Carbon::parse($earliest)
            : now()->subYears(self::ALL_TIME_FALLBACK_YEARS);
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
