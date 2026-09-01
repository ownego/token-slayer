<?php

namespace App\Services\Accounts;

use App\Models\Account;

/**
 * Read-only rows for the admin "Expiring Accounts" page: accounts whose
 * refresh-token deadline ({@see Account::$oauth_refresh_expires_at}) falls
 * within the warning window, soonest first, with each account's tracked
 * ({@see \App\Enums\MembershipStatus::Tracked}) member count so an admin
 * can triage by blast radius.
 */
final class ExpiringAccountsQuery
{
    /**
     * How far ahead of now an account counts as "expiring soon" — matches
     * the `token-slayer-cli` client's own warning threshold
     * (`REFRESH_TOKEN_WARNING_MS`), kept in sync by convention rather than
     * a shared constant across the two repos/languages.
     *
     * @var int
     */
    private const int WARNING_DAYS = 3;

    /**
     * Return accounts within the warning window, ordered soonest-deadline
     * first.
     *
     * @return array<int, array{id: int, email: ?string, plan: ?string, expires_at: \Illuminate\Support\Carbon, is_expired: bool, tracked_members: int, updated_at: ?\Illuminate\Support\Carbon}>
     */
    public function get(): array
    {
        // No lower bound: an already-past deadline must still surface here —
        // this page exists specifically so an admin discovers a dying grant
        // BEFORE it goes fully dead, not so it silently disappears the moment
        // it does. Ascending order then naturally puts the most-overdue row
        // first (its timestamp is the smallest), no special-case sort needed.
        return Account::query()
            ->whereNotNull('oauth_refresh_expires_at')
            ->where('oauth_refresh_expires_at', '<=', now()->addDays(self::WARNING_DAYS))
            ->withCount('trackedUsers')
            ->orderBy('oauth_refresh_expires_at')
            ->get()
            ->map(fn (Account $account): array => [
                'id' => $account->id,
                'email' => $account->email,
                'plan' => $account->plan?->getLabel(),
                'expires_at' => $account->oauth_refresh_expires_at,
                'is_expired' => $account->oauth_refresh_expires_at->isPast(),
                'tracked_members' => $account->tracked_users_count,
                'updated_at' => $account->updated_at,
            ])
            ->all();
    }

    /**
     * Cheap count of accounts within the warning window, for the
     * navigation badge — a plain `COUNT(*)`, no `withCount` subquery or row
     * hydration, since {@see ExpiringAccounts::getNavigationBadge()} runs
     * this on every panel page render, not only when viewing this page.
     *
     * @return int
     */
    public function count(): int
    {
        return Account::query()
            ->whereNotNull('oauth_refresh_expires_at')
            ->where('oauth_refresh_expires_at', '<=', now()->addDays(self::WARNING_DAYS))
            ->count();
    }
}
