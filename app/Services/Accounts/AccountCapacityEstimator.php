<?php

namespace App\Services\Accounts;

use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use App\Models\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * How many tokens an account's weekly quota is actually worth, measured from
 * quota windows that have already closed.
 *
 * The obvious shortcut — trailing tokens divided by the current util_7d —
 * is wrong and was the original defect here: util_7d is a percentage of
 * ANTHROPIC's rolling window, which resets at its own per-account time,
 * while the token sum covers OUR window. An account probed hours after its
 * reset reads ~1% against a full week of recorded tokens, and the ratio
 * explodes (measured live at 235x the real figure), making a freshly-reset
 * account look like it had billions of spare tokens. Only a CLOSED window
 * has a matching numerator and denominator: everything consumed inside it,
 * against the highest percentage that consumption reached.
 */
final class AccountCapacityEstimator
{
    /**
     * Lowest peak utilization a closed window may have and still be trusted
     * for extrapolation. At 25% a one-point probe error moves the estimate
     * by 4%; below that the arithmetic amplifies noise faster than it
     * reveals capacity.
     *
     * @var int
     */
    private const int MIN_PEAK_UTIL = 25;

    /**
     * Utilization at which a window stops being an estimate and becomes a
     * reading. An account that ran its quota out cut its users off, so what
     * they managed to spend IS the week's worth — no scaling, no amplified
     * error.
     *
     * Anything below this multiplies a fraction of a week up to a whole one,
     * and multiplies the probe's error with it: at 25% a single point of
     * error moves the answer by 4%. Weighing the two kinds of window equally
     * read the real fleet 10-26% larger than its own saturated weeks plainly
     * showed, and that inflation was the whole reason one account looked
     * roomy enough to send the fleet's heaviest person to.
     *
     * @var int
     */
    private const int SATURATED_PERCENT = 95;

    /**
     * The quota window length Anthropic reports util_7d against.
     *
     * @var int
     */
    private const int WINDOW_DAYS = 7;

    /**
     * Median weekly capacity in tokens for one account, or null when no
     * closed window in `$window` carries a usable signal.
     *
     * @param  Account  $account  the account to measure
     * @param  RebalanceWindow  $window  how far back to look
     * @return float|null weekly capacity in tokens, or null when unmeasurable
     */
    public function weeklyCapacityTokens(Account $account, RebalanceWindow $window): ?float
    {
        return $this->measure($account, $window)['tokens'];
    }

    /**
     * This account's weekly capacity and how it was arrived at.
     *
     * Weeks the account ran out of are used alone when there are any: each is
     * a direct reading, and mixing readings with extrapolations lets the
     * noisier evidence move an answer the quieter evidence already settled.
     *
     * @param  Account  $account  the account to measure
     * @param  RebalanceWindow  $window  how far back to look
     * @return array{tokens: float|null, basis: string, windows: int}
     */
    public function measure(Account $account, RebalanceWindow $window): array
    {
        $measured = [];
        $extrapolated = [];

        foreach ($this->closedWindows($account, $window) as $closed) {
            if ($closed['peak_util'] < self::MIN_PEAK_UTIL) {
                continue;
            }

            $tokens = $this->tokensConsumedIn($account, $closed['reset_at']);
            if ($tokens <= 0) {
                continue;
            }

            if ($closed['peak_util'] >= self::SATURATED_PERCENT) {
                $measured[] = (float) $tokens;

                continue;
            }

            $extrapolated[] = $tokens * 100 / $closed['peak_util'];
        }

        if ($measured !== []) {
            return ['tokens' => $this->median($measured), 'basis' => 'measured', 'windows' => count($measured)];
        }

        if ($extrapolated !== []) {
            return ['tokens' => $this->median($extrapolated), 'basis' => 'extrapolated', 'windows' => count($extrapolated)];
        }

        return ['tokens' => null, 'basis' => 'unknown', 'windows' => 0];
    }

    /**
     * Weekly capacity for every given account, keyed by account id. An
     * account with no usable history of its own inherits the median of the
     * accounts sharing its plan, and failing that the fleet median — a new
     * or quiet account is far more likely to resemble its siblings than to
     * genuinely have no capacity, and treating it as zero would hide the
     * one account with room to spare.
     *
     * @param  Collection<int, Account>  $accounts  the accounts to resolve
     * @param  RebalanceWindow  $window  how far back to look
     * @return array<int, array{tokens: float|null, basis: string, windows: int}> account id => capacity and how it was arrived at
     */
    public function capacitiesFor(Collection $accounts, RebalanceWindow $window): array
    {
        $resolved = [];
        foreach ($accounts as $account) {
            $resolved[$account->id] = $this->measure($account, $window);
        }

        $byPlan = [];
        foreach ($accounts as $account) {
            if ($resolved[$account->id]['tokens'] !== null) {
                $byPlan[$account->plan->value][] = $resolved[$account->id]['tokens'];
            }
        }

        $known = array_column(array_filter($resolved, fn (array $r): bool => $r['tokens'] !== null), 'tokens');
        $fleetMedian = $this->median($known);

        foreach ($accounts as $account) {
            if ($resolved[$account->id]['tokens'] !== null) {
                continue;
            }

            $inherited = $this->median($byPlan[$account->plan->value] ?? []) ?? $fleetMedian;
            $resolved[$account->id] = [
                'tokens' => $inherited,
                'basis' => $inherited === null ? 'unknown' : 'inherited',
                'windows' => 0,
            ];
        }

        return $resolved;
    }

    /**
     * The closed quota windows visible in `$window`, as reset time plus the
     * highest utilization reached. Reset timestamps are normalised to the
     * hour first: the prober records the same boundary a second either side
     * of it, and treating those as separate windows would split one week's
     * usage across two bogus half-windows.
     *
     * @param  Account  $account  the account to read snapshots for
     * @param  RebalanceWindow  $window  how far back to look
     * @return array<int, array{reset_at: Carbon, peak_util: int}>
     */
    private function closedWindows(Account $account, RebalanceWindow $window): array
    {
        $rows = AccountUsageSnapshot::query()
            ->where('account_id', $account->id)
            ->whereNotNull('reset_7d_at')
            ->whereNotNull('util_7d')
            ->when($window->since() !== null, fn ($query) => $query->where('created_at', '>=', $window->since()))
            ->selectRaw('reset_7d_at')
            ->selectRaw('MAX(util_7d) as peak_util')
            ->groupBy('reset_7d_at')
            ->get();

        $byHour = [];
        foreach ($rows as $row) {
            $resetAt = Carbon::parse($row->reset_7d_at)->startOfHour();
            if ($resetAt->isFuture()) {
                continue; // still open: only part of its usage is on record
            }

            $key = $resetAt->toDateTimeString();
            $byHour[$key] = [
                'reset_at' => $resetAt,
                'peak_util' => max((int) $row->peak_util, $byHour[$key]['peak_util'] ?? 0),
            ];
        }

        return array_values($byHour);
    }

    /**
     * Tokens recorded against this account inside the 7-day span that ended
     * at `$resetAt`.
     *
     * @param  Account  $account  the account to sum usage for
     * @param  Carbon  $resetAt  when the window closed
     * @return int
     */
    private function tokensConsumedIn(Account $account, Carbon $resetAt): int
    {
        return (int) Event::query()
            ->where('account_id', $account->id)
            ->where('created_at', '>=', $resetAt->copy()->subDays(self::WINDOW_DAYS))
            ->where('created_at', '<', $resetAt)
            ->sum('tokens');
    }

    /**
     * Median of the given values, or null when there are none. Median over
     * mean throughout: one runaway week (or one account probed mid-reset)
     * should not drag the figure every other decision is measured against.
     *
     * @param  array<int, float>  $values  the values to reduce
     * @return float|null
     */
    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
