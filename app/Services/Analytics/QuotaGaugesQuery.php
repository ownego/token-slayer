<?php

namespace App\Services\Analytics;

use App\Enums\AccountPlan;
use App\Enums\CodexPlan;
use App\Enums\Provider;
use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use App\Services\Accounts\CodexUsageWindows;
use App\Services\Accounts\ModelQuotaLimits;
use App\Services\Accounts\PlanBadgeResolver;
use App\Services\QuotaProjection;
use Illuminate\Support\Carbon;

/**
 * Builds the current per-account quota gauge rows (5h/7d utilization, reset
 * boundaries, projected-at-reset, near-cap flag) for the analytics page's
 * fleet-quota widget. Reflects live state, so it takes no time filter.
 */
final class QuotaGaugesQuery
{
    /**
     * @param  PlanBadgeResolver  $planBadges  resolves each account's plan badge value by provider
     * @return void
     */
    public function __construct(private readonly PlanBadgeResolver $planBadges) {}

    /**
     * One quota-gauge row per account, read from its latest snapshot: current
     * 5h/7d utilization, the reset boundaries, the projected utilization at
     * each reset (via {@see QuotaProjection}), and a near-cap flag
     * (`util_7d >= 85`). Accounts never probed report null utilization and
     * are not near-cap.
     *
     * @return array<int, array{account_id:int, provider:Provider, email:string, plan:AccountPlan|CodexPlan|null, util_5h:?int, util_7d:?int, reset_5h_at:?Carbon, reset_7d_at:?Carbon, projected_5h:?int, projected_7d:?int, near_cap:bool}>
     */
    /**
     * Utilization at or above which an account is flagged as near its cap.
     *
     * @var int
     */
    private const int NEAR_CAP_PERCENT = 85;

    public function get(): array
    {
        return Account::query()
            ->with(['latestUsageSnapshot', 'codexCredential'])
            ->orderBy('email')
            ->get()
            ->map(function (Account $account): array {
                $snapshot = $account->latestUsageSnapshot;

                return [
                    'account_id' => $account->id,
                    'provider' => $account->provider,
                    'email' => $account->email,
                    'plan' => $this->planBadges->for($account),
                    'util_5h' => $snapshot?->util_5h,
                    'util_7d' => $snapshot?->util_7d,
                    'reset_5h_at' => $snapshot?->reset_5h_at,
                    'reset_7d_at' => $snapshot?->reset_7d_at,
                    'projected_5h' => $this->project($snapshot?->util_5h, $snapshot?->reset_5h_at, 5, 'hours'),
                    'projected_7d' => $this->project($snapshot?->util_7d, $snapshot?->reset_7d_at, 7, 'days'),
                    // The fullest window the account actually reports, not a
                    // fixed one. This read util_7d, which a Codex account
                    // never has -- and while its figure was being misfiled
                    // into util_5h, it read the wrong column too, so the flag
                    // has never fired for Codex at all.
                    'near_cap' => $this->fullestWindow($account, $snapshot) >= self::NEAR_CAP_PERCENT,
                    // From the response's limits[], which names its own model
                    // -- see ModelQuotaLimits for why the top-level keys that
                    // look like the source are not it.
                    'model_limits' => ModelQuotaLimits::from($snapshot?->raw),
                    // Codex reports its own windows and they are not the 5h/7d
                    // pair -- a free-tier account has a single 30-day cap. The
                    // card renders these instead of the two fixed rows, so a
                    // window is never shown under a duration it does not have.
                    'codex_windows' => $account->provider === Provider::Codex
                        ? CodexUsageWindows::from($snapshot?->raw)
                        : [],
                ];
            })
            ->all();
    }

    /**
     * Project a utilization reading to its reset boundary, or null when the
     * reading or reset is unknown. The window start is the reset minus the
     * window length.
     *
     * @param  ?int  $current  the utilization reading, or null
     * @param  ?Carbon  $resetAt  when the window resets, or null
     * @param  int  $windowLength  the window length magnitude
     * @param  string  $unit  `'hours'` or `'days'`
     * @return ?int the projected percent, or null
     */
    /**
     * The highest utilization among the windows this account reports.
     *
     * Deliberately provider-agnostic and window-agnostic: Claude reports a
     * 5h/7d pair, a paid Codex account the same, and a free one a single
     * 30-day cap. Naming one window as the one that counts leaves whichever
     * accounts do not report it permanently unflagged.
     *
     * @param  Account  $account  the account being gauged
     * @param  ?AccountUsageSnapshot  $snapshot  its latest snapshot, or null
     * @return int the highest percent reported, or 0 when nothing is
     */
    private function fullestWindow(Account $account, ?AccountUsageSnapshot $snapshot): int
    {
        if ($snapshot === null) {
            return 0;
        }

        $percents = $account->provider === Provider::Codex
            ? array_column(CodexUsageWindows::from($snapshot->raw), 'percent')
            : [$snapshot->util_5h, $snapshot->util_7d];

        return (int) max([0, ...array_filter($percents, fn (?int $p): bool => $p !== null)]);
    }

    private function project(?int $current, ?Carbon $resetAt, int $windowLength, string $unit): ?int
    {
        if ($current === null || $resetAt === null) {
            return null;
        }

        $windowStart = $unit === 'days'
            ? $resetAt->copy()->subDays($windowLength)
            : $resetAt->copy()->subHours($windowLength);

        return QuotaProjection::projectedAtReset($current, $windowStart, $resetAt, now());
    }
}
