<?php

namespace App\Services\Accounts;

use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use Illuminate\Support\Carbon;

/**
 * The quota windows an account has already finished, read off its usage
 * snapshots.
 *
 * Three separate measurements rest on knowing where one week ends and the
 * next begins — how many tokens the week's quota was worth, how much the
 * account really carried in it, and how early it ran out. Each had grown its
 * own copy of this, and two of those copies carried the same defect: the API
 * reports one boundary as `05:59:59`, `06:00:00` and `06:00:01` across a week
 * of probing, and flooring those to the hour separates the first from the
 * other two instead of joining them. Every account was carrying each of its
 * weeks twice.
 *
 * One copy, rounding to the nearest hour, so a fix lands everywhere at once.
 */
final class ClosedQuotaWindows
{
    /**
     * The window length Anthropic meters `util_7d` against.
     *
     * @var int
     */
    public const int WINDOW_DAYS = 7;

    /**
     * Each closed window's boundaries and the highest utilisation it reached.
     *
     * @param  Account  $account  the account to read
     * @param  RebalanceWindow  $window  how far back to look
     * @return array<int, array{reset_at: Carbon, opened_at: Carbon, peak_util: int}>
     */
    public function peaks(Account $account, RebalanceWindow $window): array
    {
        $peaks = [];

        foreach ($this->curves($account, $window) as $curve) {
            $peaks[] = [
                'reset_at' => $curve[0]['reset_at'],
                'opened_at' => $curve[0]['opened_at'],
                'peak_util' => max(array_column($curve, 'util')),
            ];
        }

        return $peaks;
    }

    /**
     * Each closed window's readings, oldest first — the shape of the climb,
     * for callers that need where the account was on a given day rather than
     * only where it ended.
     *
     * @param  Account  $account  the account to read
     * @param  RebalanceWindow  $window  how far back to look
     * @return array<int, array<int, array{at: Carbon, util: int, reset_at: Carbon, opened_at: Carbon}>>
     */
    public function curves(Account $account, RebalanceWindow $window): array
    {
        $rows = AccountUsageSnapshot::query()
            ->where('account_id', $account->id)
            ->whereNotNull('reset_7d_at')
            ->whereNotNull('util_7d')
            ->when($window->since() !== null, fn ($query) => $query->where('created_at', '>=', $window->since()))
            ->orderBy('created_at')
            ->get(['util_7d', 'reset_7d_at', 'created_at']);

        $curves = [];

        foreach ($rows as $row) {
            $resetAt = $this->boundary(Carbon::parse($row->reset_7d_at));

            if ($resetAt->isFuture()) {
                continue; // still open: only part of its usage is on record
            }

            $curves[$resetAt->toDateTimeString()][] = [
                'at' => Carbon::parse($row->created_at),
                'util' => (int) $row->util_7d,
                'reset_at' => $resetAt,
                'opened_at' => $resetAt->copy()->subDays(self::WINDOW_DAYS),
            ];
        }

        return array_values($curves);
    }

    /**
     * The hour a reported reset time belongs to.
     *
     * Nearest rather than floor: the reports straddle the boundary by a
     * second in both directions, and flooring puts the ones a second early
     * in the hour before.
     *
     * @param  Carbon  $reportedAt  the reset time as the API gave it
     * @return Carbon
     */
    private function boundary(Carbon $reportedAt): Carbon
    {
        return $reportedAt->copy()->addMinutes(30)->startOfHour();
    }
}
