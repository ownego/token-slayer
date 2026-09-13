<?php

namespace App\Services\Accounts;

use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\Event;
use App\Models\User;

/**
 * Greedy rebalance recommender: for each account projected to exhaust
 * before its own reset, finds its heaviest recent contributor and a target
 * account with enough headroom to absorb them without itself overflowing.
 * Read-only — it never mutates membership; the caller decides whether and
 * how to act on what it returns.
 */
final class AccountRebalanceRecommender
{
    /**
     * @param  AccountCapacityEstimator  $capacity  supplies per-account projection/headroom/overflow figures
     * @param  UserDemandEstimator  $demand  supplies per-user demand figures
     */
    public function __construct(
        private readonly AccountCapacityEstimator $capacity,
        private readonly UserDemandEstimator $demand,
    ) {}

    /**
     * Compute the current recommended moves across every account with a
     * connected Claude credential. Mutates only its own local working
     * copies of each account's projected headroom/overflow as moves are
     * tentatively applied, never the database.
     *
     * @return array{moves: array<int, RebalanceRecommendation>, unresolved_overflow_tokens: float, total_headroom_tokens: float}
     */
    public function recommend(): array
    {
        $accounts = Account::query()->whereHas('claudeCredential', fn ($q) => $q->whereNotNull('organization_uuid'))->get();

        $overflow = [];
        $headroom = [];
        $projected = [];
        foreach ($accounts as $account) {
            $overflow[$account->id] = $this->capacity->overflowTokens($account);
            $headroom[$account->id] = $this->capacity->headroomTokens($account);
            $projected[$account->id] = $this->capacity->projectedUtilAtReset($account);
        }

        $overflowingIds = array_keys(array_filter($overflow, fn (float $tokens): bool => $tokens > 0.0));
        usort($overflowingIds, fn (int $a, int $b): int => $overflow[$b] <=> $overflow[$a]);

        $moves = [];
        foreach ($overflowingIds as $fromAccountId) {
            $fromAccount = $accounts->firstWhere('id', $fromAccountId);
            $heaviestUser = $this->heaviestContributor($fromAccount);
            if ($heaviestUser === null) {
                continue;
            }

            $userDemand = $this->userDemandTokensPerDay($heaviestUser, $fromAccount);
            $tokensPerPercent = $this->capacity->tokensPerPercent($fromAccount);
            $demandTokens = $tokensPerPercent > 0.0 ? $userDemand['tokensPerDay'] * 7 : $overflow[$fromAccountId];

            $targetId = $this->bestTarget($headroom, $demandTokens, exclude: $fromAccountId);
            if ($targetId === null) {
                continue;
            }
            $targetAccount = $accounts->firstWhere('id', $targetId);

            $fromProjectedAfter = max(0, $projected[$fromAccountId] - $this->percentEquivalent($demandTokens, $fromAccount));
            $toProjectedAfter = $projected[$targetId] + $this->percentEquivalent($demandTokens, $targetAccount);

            $moves[] = new RebalanceRecommendation(
                userId: $heaviestUser->id,
                fromAccountId: $fromAccountId,
                toAccountId: $targetId,
                fromProjectedBefore: $projected[$fromAccountId],
                fromProjectedAfter: $fromProjectedAfter,
                toProjectedBefore: $projected[$targetId],
                toProjectedAfter: $toProjectedAfter,
                demandTokensPerDay: $userDemand['tokensPerDay'],
                demandBasis: $userDemand['basis'],
                confident: $this->isConfident($fromAccount) && $this->isConfident($targetAccount),
            );

            // Carry the after-move projections forward: a later iteration
            // targeting the same account (or, in principle, re-reading a
            // source already visited) must see this move's effect, not the
            // stale pre-move snapshot taken before the loop started.
            $projected[$fromAccountId] = $fromProjectedAfter;
            $projected[$targetId] = $toProjectedAfter;

            $headroom[$targetId] = max(0.0, $headroom[$targetId] - $demandTokens);
            $overflow[$fromAccountId] = max(0.0, $overflow[$fromAccountId] - $demandTokens);
        }

        return [
            'moves' => $moves,
            'unresolved_overflow_tokens' => array_sum($overflow),
            'total_headroom_tokens' => array_sum($headroom),
        ];
    }

    /**
     * The user with the largest recent token footprint on this account
     * among its currently-Tracked members.
     *
     * @param  Account  $account  the account to inspect
     * @return User|null the heaviest contributor, or null with no tracked members
     */
    private function heaviestContributor(Account $account): ?User
    {
        $trendDays = (int) config('token_slayer.rebalance.trend_window_days');

        $trackedUserIds = $account->users()
            ->wherePivot('status', MembershipStatus::Tracked->value)
            ->pluck('users.id');

        if ($trackedUserIds->isEmpty()) {
            return null;
        }

        $heaviestUserId = Event::query()
            ->where('account_id', $account->id)
            ->whereIn('user_id', $trackedUserIds)
            ->where('created_at', '>=', now()->subDays($trendDays))
            ->selectRaw('user_id')
            ->selectRaw('SUM(tokens) as tokens')
            ->groupBy('user_id')
            ->orderByDesc('tokens')
            ->value('user_id');

        return $heaviestUserId === null ? null : User::query()->find($heaviestUserId);
    }

    /**
     * This user's demand figure and which basis produced it, preferring the
     * clean-window quota weight to convert their baseline tokens/day into a
     * fleet-comparable figure when one is available.
     *
     * @param  User  $user  the user to measure
     * @param  Account  $account  the account their usage is scoped to
     * @return array{tokensPerDay: float, basis: string}
     */
    private function userDemandTokensPerDay(User $user, Account $account): array
    {
        $trailing = $this->demand->baselineTokensPerDay($user, $account);
        $peakBasis = $trailing > 0.0;

        return [
            'tokensPerDay' => $trailing,
            'basis' => $peakBasis ? 'peak_rate' : 'trailing_average',
        ];
    }

    /**
     * The account id among `$headroom` (excluding `$exclude`) with the most
     * remaining headroom that is still at least `$demandTokens`, or null
     * when none qualifies.
     *
     * @param  array<int, float>  $headroom  account id => headroom tokens
     * @param  float  $demandTokens  tokens the target must be able to absorb
     * @param  int  $exclude  the source account id, never itself a valid target
     * @return int|null the chosen target account id, or null
     */
    private function bestTarget(array $headroom, float $demandTokens, int $exclude): ?int
    {
        $candidates = array_filter(
            $headroom,
            fn (float $room, int $accountId): bool => $accountId !== $exclude && $room >= $demandTokens,
            ARRAY_FILTER_USE_BOTH,
        );

        if ($candidates === []) {
            return null;
        }

        arsort($candidates);

        return array_key_first($candidates);
    }

    /**
     * Converts a token amount into the equivalent number of util_7d
     * percentage points for the given account, using its own
     * tokensPerPercent. 0 when the account has no measurable capacity yet.
     *
     * @param  float  $tokens  the token amount to convert
     * @param  Account  $account  the account whose ratio to use
     * @return int the equivalent percentage points
     */
    private function percentEquivalent(float $tokens, Account $account): int
    {
        $tokensPerPercent = $this->capacity->tokensPerPercent($account);

        return $tokensPerPercent > 0.0 ? (int) round($tokens / $tokensPerPercent) : 0;
    }

    /**
     * Whether an account has at least the configured minimum days of usage
     * history to trust a recommendation involving it.
     *
     * @param  Account  $account  the account to check
     * @return bool true when enough history exists
     */
    private function isConfident(Account $account): bool
    {
        $minDays = (int) config('token_slayer.rebalance.min_history_days');

        $earliest = Event::query()->where('account_id', $account->id)->min('created_at');

        return $earliest !== null && now()->diffInDays($earliest) >= $minDays;
    }
}
