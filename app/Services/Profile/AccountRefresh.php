<?php

namespace App\Services\Profile;

use App\Enums\Provider;
use App\Models\Account;
use App\Models\User;
use App\Services\Accounts\CodexUsageWindows;
use App\Services\Analytics\FleetUsageRefresher;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Re-probes one of a player's own accounts from its card on the fighter
 * sheet, and reports what changed the way the mockup's card shows it:
 * "42% → 45%", "failed", or how long until it may be checked again.
 */
final class AccountRefresh
{
    /**
     * How long one player waits between checks of the same account, in
     * seconds — the same minute the whole-sheet refresh uses.
     *
     * @var int
     */
    public const int COOLDOWN_SECONDS = 60;

    /**
     * The refresher this class probes through, which holds the per-account
     * probe lock so a card refresh never races the scheduled sweep.
     *
     * @param  FleetUsageRefresher  $refresher
     * @return void
     */
    public function __construct(private readonly FleetUsageRefresher $refresher) {}

    /**
     * Probes the account and reports the outcome.
     *
     * @param  User  $user  the player asking
     * @param  int  $accountId  the card's account
     * @return array{status: 'changed'|'unchanged'|'failed'|'cooldown'|'forbidden', from?: ?int, to?: ?int, error?: string, seconds?: int}
     */
    public function execute(User $user, int $accountId): array
    {
        $account = $user->trackedAccounts()->whereKey($accountId)->first();

        if ($account === null) {
            return ['status' => 'forbidden'];
        }

        $key = "fighter-sheet-refresh:{$user->id}:{$account->id}";
        $from = $this->headline($account);
        $ran = RateLimiter::attempt($key, 1, fn () => $this->refresher->refreshAccounts(collect([$account])), self::COOLDOWN_SECONDS);

        if (! $ran) {
            return ['status' => 'cooldown', 'seconds' => RateLimiter::availableIn($key)];
        }

        $account->refresh();

        if ($account->probe_error !== null) {
            return ['status' => 'failed', 'error' => $account->probe_error];
        }

        $to = $this->headline($account);

        return ['status' => $from === $to ? 'unchanged' : 'changed', 'from' => $from, 'to' => $to];
    }

    /**
     * The percent the card's first meter shows: a Codex account's first own
     * window, otherwise the 5-hour window (the 7-day one when 5-hour is
     * unreported).
     *
     * @param  Account  $account
     * @return ?int
     */
    private function headline(Account $account): ?int
    {
        $snapshot = $account->latestUsageSnapshot()->first();

        if ($snapshot === null) {
            return null;
        }

        if ($account->provider === Provider::Codex) {
            return CodexUsageWindows::from($snapshot->raw)[0]['percent'] ?? null;
        }

        return $snapshot->util_5h ?? $snapshot->util_7d;
    }
}
