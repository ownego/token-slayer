<?php

namespace App\Services\Accounts;

use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Which single account each person actually works on today.
 *
 * A person belongs to one account; the ledger just does not always say so
 * cleanly. Two things put their usage on more than one: a brief spill, when
 * someone bounces off a full account and comes straight back, and a genuine
 * migration, when they hand over and never return. Only the second changes
 * where they live, and telling them apart is what stops the planner
 * proposing moves off an account nobody has used in a fortnight.
 *
 * Recent usage decides, because a migration is always visible there first
 * and a spill is always outweighed by the week around it. Only when nobody
 * has worked recently does the whole range get a say.
 */
final class HomeAccountResolver
{
    /**
     * How far back counts as "where they are now". Short enough that a
     * handover shows up within days of happening, long enough that a two-day
     * spill cannot outvote the week it sits in.
     *
     * @var int
     */
    private const int RECENT_DAYS = 7;

    /**
     * Each person's home account, keyed by user id, across the tracked and
     * pending memberships of the given accounts.
     *
     * Pending members count: a slot has been granted and they will start
     * spending on it, so planning as though they were not there is planning
     * for a fleet that is about to change.
     *
     * @param  Collection<int, Account>  $accounts  the accounts in scope
     * @param  RebalanceWindow  $window  the range to fall back on
     * @return array<int, int> user id => account id
     */
    public function resolve(Collection $accounts, RebalanceWindow $window): array
    {
        $accountIds = $accounts->pluck('id')->all();

        $recent = $this->tokensByPair($accountIds, now()->subDays(self::RECENT_DAYS));
        $overall = $this->tokensByPair($accountIds, $window->since());

        $memberships = [];

        $rows = DB::table('account_user')
            ->whereIn('account_id', $accountIds)
            ->whereIn('status', [MembershipStatus::Tracked->value, MembershipStatus::Pending->value])
            ->orderBy('account_id')
            ->get(['user_id', 'account_id']);

        foreach ($rows as $row) {
            $userId = (int) $row->user_id;
            $accountId = (int) $row->account_id;

            $candidate = [
                'account_id' => $accountId,
                'recent' => $recent["{$userId}:{$accountId}"] ?? 0,
                'overall' => $overall["{$userId}:{$accountId}"] ?? 0,
            ];

            $held = $memberships[$userId] ?? null;
            if ($held === null || $this->beats($candidate, $held)) {
                $memberships[$userId] = $candidate;
            }
        }

        return array_map(fn (array $held): int => $held['account_id'], $memberships);
    }

    /**
     * Whether one membership is a better claim to being home than another:
     * recent usage first, the whole range only as a tie-break.
     *
     * @param  array{account_id: int, recent: int, overall: int}  $candidate  the challenger
     * @param  array{account_id: int, recent: int, overall: int}  $held  the incumbent
     * @return bool
     */
    private function beats(array $candidate, array $held): bool
    {
        if ($candidate['recent'] !== $held['recent']) {
            return $candidate['recent'] > $held['recent'];
        }

        return $candidate['overall'] > $held['overall'];
    }

    /**
     * Tokens each person spent on each account since `$since`, keyed
     * `"<user>:<account>"`.
     *
     * @param  array<int, int>  $accountIds  the accounts in scope
     * @param  Carbon|null  $since  the earliest moment to count, or null for everything
     * @return array<string, int>
     */
    private function tokensByPair(array $accountIds, $since): array
    {
        return Event::query()
            ->whereIn('account_id', $accountIds)
            ->when($since !== null, fn ($query) => $query->where('created_at', '>=', $since))
            ->selectRaw('user_id')
            ->selectRaw('account_id')
            ->selectRaw('SUM(tokens) as tokens')
            ->groupBy('user_id', 'account_id')
            ->get()
            ->mapWithKeys(fn ($row): array => ["{$row->user_id}:{$row->account_id}" => (int) $row->tokens])
            ->all();
    }
}
