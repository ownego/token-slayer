<?php

namespace App\Services\Accounts;

use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns measured capacity and measured demand into a set of membership moves.
 *
 * The work is split three ways on purpose: {@see AccountCapacityEstimator}
 * answers how big each account is, {@see UserDemandEstimator} answers how big
 * each person is, and {@see RebalancePlanner} answers where everyone should
 * sit. This class only joins them up, decides whether the resulting plan is
 * worth acting on, and attaches the workings to each move.
 *
 * Read-only: it never touches membership. The admin decides whether to act.
 */
final class AccountRebalanceRecommender
{
    /**
     * @param  AccountCapacityEstimator  $capacity  measures how many tokens each account's weekly quota is worth
     * @param  UserDemandEstimator  $demand  measures each person's appetite and how heavily their tokens bite
     * @param  RebalancePlanner  $planner  decides the arrangement these two measurements imply
     */
    public function __construct(
        private readonly AccountCapacityEstimator $capacity,
        private readonly UserDemandEstimator $demand,
        private readonly RebalancePlanner $planner,
    ) {}

    /**
     * The current recommendation for the fleet, read over `$window`.
     *
     * @param  RebalanceWindow|null  $window  how far back to read, defaulting to the configured trend window
     * @return array{moves: array<int, RebalanceRecommendation>, accounts: array<int, array{id: int, email: string, capacity_tokens: float, fill_before_percent: float, fill_after_percent: float, members_before: int, members_after: int}>, peak_fill_before_percent: float, peak_fill_after_percent: float, unplaced_tokens: float, safety_margin_percent: int, window_label: string}
     */
    public function recommend(?RebalanceWindow $window = null): array
    {
        $window ??= RebalanceWindow::fromFilter(null);

        $accounts = Account::query()
            ->whereHas('claudeCredential', fn ($query) => $query->whereNotNull('organization_uuid'))
            ->with('claudeCredential')
            ->get();

        $capacities = array_filter(
            $this->capacity->capacitiesFor($accounts, $window),
            fn (?float $tokens): bool => $tokens !== null && $tokens > 0.0,
        );

        $current = $this->currentMemberships($accounts, $window);
        $users = User::query()->whereIn('id', array_keys($current))->get()->keyBy('id');
        $weights = $this->demand->quotaWeights($accounts, $window);

        $demands = [];
        $burstFactors = [];
        $details = [];

        foreach ($current as $userId => $accountId) {
            $user = $users->get($userId);
            if ($user === null) {
                continue;
            }

            $measured = $this->demand->demandFor($user, $window);
            $weight = $weights[$userId] ?? 1.0;
            $weekly = $measured['weekly'] * $weight;
            if ($weekly <= 0.0) {
                continue; // nobody to plan around; leave them where they are
            }

            $demands[$userId] = $weekly;
            $burstFactors[$userId] = $this->demand->burstFactor($user, $window);
            $details[$userId] = $measured + ['quota_weight' => $weight];
        }

        $margin = ((int) config('token_slayer.rebalance.safety_margin_percent')) / 100;

        $plan = $this->planner->plan(
            capacities: $capacities,
            demands: $demands,
            current: array_intersect_key($current, $demands),
            burstFactors: $burstFactors,
            safetyMargin: $margin,
        );

        $fillBefore = $this->fillPercentages($plan['fill_before'], $capacities);
        $fillAfter = $this->fillPercentages($plan['fill_after'], $capacities);

        return [
            'moves' => $this->recommendationsFor($plan['moves'], $details, $burstFactors, $fillBefore, $fillAfter, $accounts),
            'accounts' => $this->accountSummaries($accounts, $capacities, $fillBefore, $fillAfter, $current, $plan['assignment']),
            'peak_fill_before_percent' => $fillBefore === [] ? 0.0 : max($fillBefore),
            'peak_fill_after_percent' => $fillAfter === [] ? 0.0 : max($fillAfter),
            'unplaced_tokens' => array_sum($plan['overflow']),
            'safety_margin_percent' => (int) config('token_slayer.rebalance.safety_margin_percent'),
            'window_label' => $window->label(),
        ];
    }

    /**
     * Where each person sits today: user id => account id. Someone tracked
     * on several accounts is credited to the one they actually spend on,
     * since that is the membership a move would be taking them off; ties go
     * to the lowest account id so a run is reproducible.
     *
     * Pending members count alongside tracked ones — they have been granted
     * a slot and will start spending on it, so planning as though they were
     * not there is planning for a fleet that is about to change.
     *
     * @param  Collection<int, Account>  $accounts  the accounts in scope
     * @param  RebalanceWindow  $window  how far back to read usage for the tie-break
     * @return array<int, int> user id => account id
     */
    private function currentMemberships(Collection $accounts, RebalanceWindow $window): array
    {
        $accountIds = $accounts->pluck('id')->all();

        $tokens = Event::query()
            ->whereIn('account_id', $accountIds)
            ->when($window->since() !== null, fn ($query) => $query->where('created_at', '>=', $window->since()))
            ->selectRaw('user_id')
            ->selectRaw('account_id')
            ->selectRaw('SUM(tokens) as tokens')
            ->groupBy('user_id', 'account_id')
            ->get()
            ->mapWithKeys(fn ($row): array => ["{$row->user_id}:{$row->account_id}" => (int) $row->tokens])
            ->all();

        $memberships = [];

        $rows = DB::table('account_user')
            ->whereIn('account_id', $accountIds)
            ->whereIn('status', [MembershipStatus::Tracked->value, MembershipStatus::Pending->value])
            ->orderBy('account_id')
            ->get(['user_id', 'account_id']);

        foreach ($rows as $row) {
            $userId = (int) $row->user_id;
            $accountId = (int) $row->account_id;
            $spend = $tokens["{$userId}:{$accountId}"] ?? 0;

            if (! isset($memberships[$userId]) || $spend > $memberships[$userId]['tokens']) {
                $memberships[$userId] = ['account_id' => $accountId, 'tokens' => $spend];
            }
        }

        return array_map(fn (array $held): int => $held['account_id'], $memberships);
    }

    /**
     * Dress each planned move up with the measurements behind it.
     *
     * @param  array<int, array{user: array-key, from: int, to: int, swap_with: array-key|null}>  $moves  the planner's raw diff
     * @param  array<int, array{weekly: float, per_day: float, trailing_avg_per_day: float, peak_week_tokens: float, basis: string, days_of_history: int, quota_weight: float}>  $details  per-user demand workings
     * @param  array<int, float>  $burstFactors  per-user burstiness
     * @param  array<int, float>  $fillBefore  account id => percentage of capacity, before
     * @param  array<int, float>  $fillAfter  account id => percentage of capacity, after
     * @param  Collection<int, Account>  $accounts  the accounts in scope, for the history check
     * @return array<int, RebalanceRecommendation>
     */
    private function recommendationsFor(
        array $moves,
        array $details,
        array $burstFactors,
        array $fillBefore,
        array $fillAfter,
        Collection $accounts,
    ): array {
        if ($moves === []) {
            return [];
        }

        $minDays = (int) config('token_slayer.rebalance.min_history_days');
        $accountDays = $this->daysOfHistory('account_id', $accounts->pluck('id')->all());
        $userDays = $this->daysOfHistory('user_id', array_map(fn (array $move): int => (int) $move['user'], $moves));

        $recommendations = [];

        foreach ($moves as $move) {
            $userId = (int) $move['user'];
            $detail = $details[$userId];

            $confident = ($userDays[$userId] ?? 0) >= $minDays
                && ($accountDays[$move['from']] ?? 0) >= $minDays
                && ($accountDays[$move['to']] ?? 0) >= $minDays;

            $recommendations[] = new RebalanceRecommendation(
                userId: $userId,
                fromAccountId: $move['from'],
                toAccountId: $move['to'],
                swapWithUserId: $move['swap_with'] === null ? null : (int) $move['swap_with'],
                demandWeeklyTokens: $detail['weekly'] * $detail['quota_weight'],
                demandPerDayTokens: $detail['per_day'],
                demandBasis: $detail['basis'],
                trailingAvgPerDayTokens: $detail['trailing_avg_per_day'],
                peakWeekTokens: $detail['peak_week_tokens'],
                burstFactor: $burstFactors[$userId] ?? 1.0,
                quotaWeight: $detail['quota_weight'],
                daysOfHistory: $detail['days_of_history'],
                fromFillBeforePercent: $fillBefore[$move['from']] ?? 0.0,
                fromFillAfterPercent: $fillAfter[$move['from']] ?? 0.0,
                toFillBeforePercent: $fillBefore[$move['to']] ?? 0.0,
                toFillAfterPercent: $fillAfter[$move['to']] ?? 0.0,
                confident: $confident,
            );
        }

        return $recommendations;
    }

    /**
     * Per-account figures for the summary the page renders above the table,
     * so the moves can be read against the fleet they are changing.
     *
     * @param  Collection<int, Account>  $accounts  the accounts in scope
     * @param  array<int, float>  $capacities  account id => weekly capacity tokens
     * @param  array<int, float>  $fillBefore  account id => percentage of capacity, before
     * @param  array<int, float>  $fillAfter  account id => percentage of capacity, after
     * @param  array<int, int>  $current  user id => account id today
     * @param  array<int, int>  $assignment  user id => account id under the plan
     * @return array<int, array{id: int, email: string, capacity_tokens: float, fill_before_percent: float, fill_after_percent: float, members_before: int, members_after: int}>
     */
    private function accountSummaries(
        Collection $accounts,
        array $capacities,
        array $fillBefore,
        array $fillAfter,
        array $current,
        array $assignment,
    ): array {
        $before = array_count_values($current);
        $after = array_count_values($assignment);

        $summaries = [];

        foreach ($accounts as $account) {
            if (! array_key_exists($account->id, $capacities)) {
                continue; // nothing measurable to report about it yet
            }

            $summaries[$account->id] = [
                'id' => $account->id,
                'email' => (string) $account->email,
                'capacity_tokens' => $capacities[$account->id],
                'fill_before_percent' => $fillBefore[$account->id] ?? 0.0,
                'fill_after_percent' => $fillAfter[$account->id] ?? 0.0,
                'members_before' => $before[$account->id] ?? 0,
                'members_after' => $after[$account->id] ?? 0,
            ];
        }

        return $summaries;
    }

    /**
     * Turn planned token loads into percentages of each account's capacity —
     * the only form in which two accounts of different sizes can be compared,
     * and the same scale the quota gauges elsewhere already use.
     *
     * @param  array<int, float>  $fills  account id => planned tokens
     * @param  array<int, float>  $capacities  account id => weekly capacity tokens
     * @return array<int, float>
     */
    private function fillPercentages(array $fills, array $capacities): array
    {
        $percentages = [];

        foreach ($fills as $accountId => $tokens) {
            $capacity = $capacities[$accountId] ?? 0.0;
            $percentages[$accountId] = $capacity > 0.0 ? $tokens * 100 / $capacity : 0.0;
        }

        return $percentages;
    }

    /**
     * Days since the earliest event recorded against each of the given ids,
     * in one query — how long we have been watching, which is what decides
     * whether a recommendation is trustworthy or merely arithmetic.
     *
     * Deliberately unscoped by the analysis window: a fortnight's window
     * cannot tell you whether an account is a month old or two days old, and
     * that is exactly the question confidence turns on.
     *
     * @param  string  $column  `'account_id'` or `'user_id'`
     * @param  array<int, int>  $ids  the ids to measure
     * @return array<int, int> id => days of recorded history
     */
    private function daysOfHistory(string $column, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Event::query()
            ->whereIn($column, $ids)
            ->selectRaw("{$column} as subject")
            ->selectRaw('MIN(created_at) as earliest')
            ->groupBy($column)
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (int) $row->subject => (int) now()->diffInDays(Carbon::parse($row->earliest), absolute: true),
            ])
            ->all();
    }
}
