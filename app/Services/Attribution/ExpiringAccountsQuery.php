<?php

namespace App\Services\Attribution;

use App\Enums\AccountStatus;
use App\Enums\GrantStatus;
use App\Enums\MembershipStatus;
use App\Enums\Provider;
use App\Filament\Resources\Accounts\RelationManagers\ProvisionsRelationManager;
use App\Models\Account;
use App\Models\AccountProvisionedGrant;
use App\Models\AccountUser;
use App\Models\ClaudeCredential;
use App\Models\Event;
use App\Support\CacheKeys;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Accounts an admin should act on. The page built on this doubles as the
 * reminder — its rows do not clear until someone repairs them, and its
 * navigation badge carries the count — so anything an admin must fix belongs
 * here, not only what is about to break.
 *
 * A Claude account qualifies three ways, checked in that order of certainty:
 * its grant was already rejected (`needs_reauth`); its refresh token expires
 * within 3 days (a real, precise deadline); or it has not rotated
 * successfully in over 2 days. The last of those is what catches a grant
 * whose refresh is failing transiently — a rate limit or a network error,
 * both of which deliberately leave the status Active so the next cycle
 * retries. Waiting for the deadline instead would catch it too, but roughly
 * 26 days later, since a frozen deadline only drifts into the 3-day window
 * near the end of the refresh token's ~29-day life.
 *
 * A Codex account qualifies when `earliest_refresh_at` has passed, or (when
 * that field is absent, which is the common case under the current
 * provisioning design — see the Phase 2 spec's 2026-09-02 correction)
 * `last_refreshed_at` is over 8 days old, mirroring the `codex` CLI's own
 * internal proactive-refresh heuristic.
 *
 * A third bucket, `kind: 'grant'`, exists beside the two account-level
 * buckets above (`kind: 'account'`): a claimed grant whose OWN
 * `session_expires_at` falls inside the same 3-day window. This exists
 * because `oauth_refresh_expires_at` is one field shared by the whole
 * account — the server's own probe refresher and every device's self-report
 * all race to push it forward, so one healthy channel hides every other
 * device quietly running out. A member's session died with zero warning
 * this way once the account it shared still looked perfectly healthy.
 */
final class ExpiringAccountsQuery
{
    /**
     * Whether `$account` already has a grant an admin should treat as
     * "handled, awaiting pull" — a live (non-revoked), still-Pending grant
     * younger than {@see CacheKeys::PROVISIONED_GRANT_PENDING_BADGE_SECONDS}.
     * Reuses that exact threshold rather than inventing a second one: it is
     * the same moment {@see ProvisionsRelationManager}
     * starts badging the row "Pending (expired)", so the two surfaces agree
     * on when "just issued" stops meaning that.
     *
     * A `Claimed` grant does not count — an employee having already pulled it
     * is not the thing this signal exists to reassure the admin about, and
     * this account is on the Expiring list for its OWN credential's health,
     * unrelated to whether any one device's grant was ever claimed.
     *
     * @param  Account  $account  the account being checked
     * @return bool
     */
    private function hasFreshPendingGrant(Account $account): bool
    {
        return $account->provisionedGrants()
            ->where('status', GrantStatus::Pending->value)
            ->where('provisioned_at', '>', now()->subSeconds(CacheKeys::PROVISIONED_GRANT_PENDING_BADGE_SECONDS))
            ->exists();
    }

    /**
     * @var int
     */
    private const int CLAUDE_WARNING_DAYS = 3;

    /**
     * How long a Claude grant may go without a successful rotation before it
     * is treated as stalled. A healthy grant rotates every few hours — the
     * refresher exchanges a token once the 8-hour access token is within 4
     * hours of expiry — so two days is well clear of any ordinary gap while
     * still being weeks earlier than the deadline would notice.
     *
     * @var int
     */
    private const int CLAUDE_STALENESS_DAYS = 2;

    /**
     * @var int
     */
    private const int CODEX_STALENESS_DAYS = 8;

    /**
     * @return array<int, array{account_id:int, email:?string, name:?string, provider:Provider, label:string, deadline:?Carbon, has_fresh_pending_grant:bool, kind:string}>
     */
    public function get(): array
    {
        return $this->claudeRows()->concat($this->codexRows())->concat($this->claudeGrantRows())->values()->all();
    }

    /**
     * @return Collection<int, array{account_id:int, email:?string, name:?string, provider:Provider, label:string, deadline:?Carbon, has_fresh_pending_grant:bool, kind:string}>
     */
    private function claudeRows(): Collection
    {
        return Account::query()
            ->where('provider', Provider::Claude)
            ->whereHas('claudeCredential', function ($credentials): void {
                // Disabled is a deliberate admin choice, not a fault: it must
                // never sit in the badge count nagging to be repaired.
                $credentials
                    ->where('status', '!=', AccountStatus::Disabled->value)
                    ->where(function ($faulty): void {
                        $faulty
                            ->where('status', AccountStatus::NeedsReauth->value)
                            ->orWhere(fn ($q) => $q
                                ->whereNotNull('oauth_refresh_expires_at')
                                ->where('oauth_refresh_expires_at', '<=', now()->addDays(self::CLAUDE_WARNING_DAYS)))
                            // Null is "never recorded", not "never refreshed":
                            // every row predating the column reads null, and
                            // treating that as stalled would flag the whole
                            // fleet the day this ships.
                            ->orWhere(fn ($q) => $q
                                ->whereNotNull('last_refreshed_at')
                                ->where('last_refreshed_at', '<', now()->subDays(self::CLAUDE_STALENESS_DAYS)));
                    });
            })
            ->with('claudeCredential')
            ->get()
            ->map(fn (Account $account): array => [
                'account_id' => $account->id,
                'email' => $account->email,
                'name' => $account->name,
                'provider' => Provider::Claude,
                'label' => self::claudeLabel($account->claudeCredential),
                // Only a real expiry deadline is a deadline. A rejected or
                // stalled grant has no date to count down to, and inventing
                // one would rank it against accounts that do have one.
                'deadline' => $account->claudeCredential->status === AccountStatus::NeedsReauth
                    ? null
                    : $account->claudeCredential->oauth_refresh_expires_at,
                'has_fresh_pending_grant' => $this->hasFreshPendingGrant($account),
                'kind' => 'account',
            ]);
    }

    /**
     * What a Claude row says is wrong with it, most certain reason first: a
     * rejected grant is a fact, an approaching deadline is a forecast, and a
     * stalled rotation is an inference.
     *
     * @param  ClaudeCredential  $credential  the account's Claude credential
     * @return string
     */
    private static function claudeLabel(ClaudeCredential $credential): string
    {
        if ($credential->status === AccountStatus::NeedsReauth) {
            return 'needs re-auth — the grant was rejected';
        }

        $deadline = $credential->oauth_refresh_expires_at;

        if ($deadline !== null && $deadline->lte(now()->addDays(self::CLAUDE_WARNING_DAYS))) {
            return 'expires '.$deadline->diffForHumans();
        }

        return "hasn't refreshed since ".$credential->last_refreshed_at->diffForHumans();
    }

    /**
     * @return Collection<int, array{account_id:int, email:?string, name:?string, provider:Provider, label:string, deadline:?Carbon, has_fresh_pending_grant:bool}>
     */
    private function codexRows(): Collection
    {
        return Account::query()
            ->where('provider', Provider::Codex)
            ->whereHas('codexCredential', function ($query): void {
                $query->where(function ($q): void {
                    $q->whereNotNull('earliest_refresh_at')->where('earliest_refresh_at', '<=', now());
                })->orWhere(function ($q): void {
                    $q->whereNull('earliest_refresh_at')->where('last_refreshed_at', '<', now()->subDays(self::CODEX_STALENESS_DAYS));
                });
            })
            ->with('codexCredential')
            ->get()
            ->map(fn (Account $account): array => [
                'account_id' => $account->id,
                'email' => $account->email,
                'name' => $account->name,
                'provider' => Provider::Codex,
                'label' => "hasn't refreshed recently — may need attention",
                'deadline' => null,
                'has_fresh_pending_grant' => $this->hasFreshPendingGrant($account),
                'kind' => 'account',
            ]);
    }

    /**
     * Live Claude grants whose own `session_expires_at` falls inside the
     * warning window, independent of the account's shared credential — see
     * the class docblock. An estimated deadline (backfilled for a grant
     * whose secret was cleared before this column existed) is dropped when
     * the account has since recorded real activity past it: that is
     * evidence the guess was wrong for this grant, and a guess already
     * disproved by the account's own ledger is worse than no signal.
     *
     * @return Collection<int, array{account_id:int, email:?string, name:?string, provider:Provider, label:string, deadline:?Carbon, has_fresh_pending_grant:bool, kind:string}>
     */
    private function claudeGrantRows(): Collection
    {
        return AccountProvisionedGrant::query()
            ->live()
            ->whereHas('account', fn ($query) => $query->where('provider', Provider::Claude))
            ->whereNotNull('session_expires_at')
            ->where('session_expires_at', '<=', now()->addDays(self::CLAUDE_WARNING_DAYS))
            ->with(['account', 'device.user'])
            ->get()
            ->reject(fn (AccountProvisionedGrant $grant): bool => $this->estimateOutlived($grant) || $this->notAMember($grant))
            ->map(fn (AccountProvisionedGrant $grant): array => [
                'account_id' => $grant->account_id,
                'email' => $grant->account->email,
                'name' => $grant->account->email.' · '.$grant->device->user->email,
                'provider' => Provider::Claude,
                'label' => self::grantLabel($grant),
                'deadline' => $grant->session_expires_at,
                'has_fresh_pending_grant' => $this->hasFreshPendingGrant($grant->account),
                'kind' => 'grant',
            ])
            ->values();
    }

    /**
     * What a grant row says is wrong with it: whose session it is and
     * whether the deadline is a real reading or a backfilled guess.
     *
     * @param  AccountProvisionedGrant  $grant  the grant to describe
     * @return string
     */
    private static function grantLabel(AccountProvisionedGrant $grant): string
    {
        $who = $grant->device->user->email;
        $when = $grant->session_expires_at->diffForHumans();

        return $grant->session_expires_at_estimated
            ? "{$who}'s own session expires ~{$when} (estimated)"
            : "{$who}'s own session expires {$when}";
    }

    /**
     * Whether `$grant`'s deadline is an unverified guess that the account's
     * own event history has already disproved — real usage recorded on this
     * (account, user) pair after the guessed deadline.
     *
     * @param  AccountProvisionedGrant  $grant  the grant to check
     * @return bool
     */
    private function estimateOutlived(AccountProvisionedGrant $grant): bool
    {
        if (! $grant->session_expires_at_estimated) {
            return false;
        }

        return Event::query()
            ->where('account_id', $grant->account_id)
            ->where('user_id', $grant->device->user_id)
            ->where('created_at', '>', $grant->session_expires_at)
            ->exists();
    }

    /**
     * Whether `$grant`'s holder is no longer an active member of the
     * account — Untracked, or no membership row at all. The grant itself
     * can outlive a membership change (leaving the account does not revoke
     * every device's grant on it), so grant status alone is not enough:
     * nagging an admin to reissue a session for someone who has left the
     * account entirely is worse than no signal.
     *
     * @param  AccountProvisionedGrant  $grant  the grant to check
     * @return bool
     */
    private function notAMember(AccountProvisionedGrant $grant): bool
    {
        $status = AccountUser::query()
            ->where('account_id', $grant->account_id)
            ->where('user_id', $grant->device->user_id)
            ->value('status');

        // `value()` still hydrates through the model's cast, so this is a
        // MembershipStatus enum (or null), never a raw string.
        return ! in_array($status, [MembershipStatus::Tracked, MembershipStatus::Pending], true);
    }
}
