<?php

namespace App\Services\Accounts;

use Illuminate\Support\Carbon;

/**
 * The per-model quota buckets inside a stored usage-probe response.
 *
 * Anthropic reports one bucket per model line alongside the account's own
 * five-hour and seven-day figures, and that set moves on its own schedule:
 * `seven_day_opus` and `seven_day_sonnet` were in a response captured in July
 * and are absent from the ones arriving now, while a bucket named
 * `nimbus_quill` has appeared. Buckets also show up under a codename before
 * the model they belong to is announced.
 *
 * So these are read out of the stored response rather than bolted onto typed
 * columns. Two columns were once added that way, `util_7d_sonnet` and
 * `util_7d_oi`; both quietly went null when the keys behind them stopped
 * being sent, and nothing displayed them anyway. Reading the response keeps
 * every bucket — including the ones already recorded historically — without a
 * migration per model.
 */
final class UsageBuckets
{
    /**
     * The account's own quota, reported alongside the per-model buckets and
     * surfaced as its own figures. Repeating them in a per-model breakdown
     * would read as two more models.
     *
     * @var array<int, string>
     */
    private const array HEADLINE = ['five_hour', 'seven_day'];

    /**
     * The per-model buckets in a probe response, fullest first.
     *
     * Recognised by shape rather than by name — an entry reporting a numeric
     * `utilization` — because naming them would be the hardcoding this exists
     * to remove. That also excludes what only looks like a bucket:
     * `extra_usage` reports null, and `limits`/`spend`/the flags are not
     * buckets at all.
     *
     * @param  mixed  $raw  the stored probe response, whatever shape it is in
     * @return array<int, array{key: string, utilization: int, resets_at: ?Carbon}>
     */
    public static function from(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $buckets = [];

        foreach ($raw as $key => $bucket) {
            if (in_array($key, self::HEADLINE, true) || ! is_array($bucket)) {
                continue;
            }

            $utilization = $bucket['utilization'] ?? null;

            if (! is_numeric($utilization)) {
                continue;
            }

            $resetsAt = $bucket['resets_at'] ?? null;

            $buckets[] = [
                'key' => (string) $key,
                'utilization' => (int) round((float) $utilization),
                'resets_at' => is_string($resetsAt) ? Carbon::parse($resetsAt) : null,
            ];
        }

        usort($buckets, fn (array $a, array $b): int => $b['utilization'] <=> $a['utilization']);

        return $buckets;
    }
}
