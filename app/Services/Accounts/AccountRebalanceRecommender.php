<?php

namespace App\Services\Accounts;

use App\Models\Account;
use App\Models\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Turns measured capacity and measured demand into a set of membership moves.
 *
 * The work is split on purpose: {@see FleetSnapshot} measures the fleet,
 * and {@see RebalancePlanner} decides where everyone should sit given those
 * measurements. This class only joins them up and attaches the workings to
 * each move.
 *
 * Read-only: it never touches membership. The admin decides whether to act.
 */
final class AccountRebalanceRecommender
{
    /**
     * @param  FleetSnapshot  $snapshot  reads the fleet's capacities, memberships and demands
     * @param  RebalancePlanner  $planner  decides the arrangement those measurements imply
     */
    public function __construct(
        private readonly FleetSnapshot $snapshot,
        private readonly RebalancePlanner $planner,
    ) {}

    /**
     * The current recommendation for the fleet, read over `$window`.
     *
     * @param  RebalanceWindow|null  $window  how far back to read, defaulting to the configured trend window
     * @param  FleetReading|null  $reading  a reading already taken over that window; measuring the fleet is by far the most expensive part of this, and a caller that also asks for a capacity forecast should not pay for it twice
     * @return array{moves: array<int, RebalanceRecommendation>, accounts: array<int, array{id: int, email: string, capacity_tokens: float, fill_before_percent: float, fill_after_percent: float, members_before: int, members_after: int}>, peak_fill_before_percent: float, peak_fill_after_percent: float, unplaced_tokens: float, safety_margin_percent: int, window_label: string}
     */
    public function recommend(?RebalanceWindow $window = null, ?FleetReading $reading = null): array
    {
        $reading ??= $this->snapshot->take($window ?? RebalanceWindow::fromFilter(null));
        $capacities = $reading->capacities;

        $plan = $this->planner->plan(
            capacities: $capacities,
            demands: $reading->demands,
            current: $reading->current,
            burstFactors: $reading->burstFactors,
            safetyMargin: $reading->safetyMargin(),
        );

        $fillBefore = $this->fillPercentages($plan['fill_before'], $capacities);
        $fillAfter = $this->fillPercentages($plan['fill_after'], $capacities);

        return [
            'moves' => $this->recommendationsFor($plan['moves'], $reading->details, $reading->burstFactors, $fillBefore, $fillAfter, $reading->accounts),
            'accounts' => $this->accountSummaries($reading->accounts, $capacities, $fillBefore, $fillAfter, $reading->current, $plan['assignment']),
            'peak_fill_before_percent' => $fillBefore === [] ? 0.0 : max($fillBefore),
            'peak_fill_after_percent' => $fillAfter === [] ? 0.0 : max($fillAfter),
            'unplaced_tokens' => (float) array_sum($plan['overflow']),
            'safety_margin_percent' => (int) config('token_slayer.rebalance.safety_margin_percent'),
            'window_label' => $reading->window->label(),
        ];
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
