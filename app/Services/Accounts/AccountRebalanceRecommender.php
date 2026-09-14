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
     * @param  int  $extraAccounts  how many accounts to plan as though they had been bought; the moves onto them cannot be executed yet, but a plan that ignores an account about to exist is planning the wrong fleet
     * @return array{moves: array<int, RebalanceRecommendation>, accounts: array<int, array{id: int, email: string, is_new: bool, capacity_basis: string, capacity_tokens: float, fill_before_percent: float, fill_after_percent: float, members_before: int, members_after: int}>, peak_fill_before_percent: float, peak_fill_after_percent: float, unplaced_tokens: float, safety_margin_percent: int, window_label: string, simulated_accounts: int, capacity_tokens: float}
     */
    public function recommend(?RebalanceWindow $window = null, ?FleetReading $reading = null, int $extraAccounts = 0): array
    {
        $reading ??= $this->snapshot->take($window ?? RebalanceWindow::fromFilter(null));

        // Hypothetical accounts take negative ids: nothing real can collide
        // with them, and anything that leaks one into a lookup for a real
        // account fails loudly instead of quietly hitting the wrong one.
        $capacities = $reading->capacities;
        for ($index = 1; $index <= $extraAccounts; $index++) {
            $capacities[-$index] = $reading->medianCapacity();
        }

        $plan = $this->planner->plan(
            capacities: $capacities,
            demands: $reading->demands,
            current: $reading->current,
            burstFactors: $reading->burstFactors,
            safetyMargin: $reading->safetyMargin(),
            // A simulated fleet has no admin to tire out: cutting it off at
            // ten would understate what the new account is worth.
            maxMoves: $extraAccounts > 0 ? max(1, count($reading->demands)) : null,
        );

        $fillBefore = $this->fillPercentages($plan['fill_before'], $capacities);
        $fillAfter = $this->fillPercentages($plan['fill_after'], $capacities);

        return [
            'moves' => $this->recommendationsFor($plan['moves'], $reading->details, $reading->burstFactors, $fillBefore, $fillAfter, $reading->accounts),
            'accounts' => $this->accountSummaries($reading->accounts, $capacities, $fillBefore, $fillAfter, $reading->current, $plan['assignment'], $reading->capacityBasis),
            'peak_fill_before_percent' => $fillBefore === [] ? 0.0 : max($fillBefore),
            'peak_fill_after_percent' => $fillAfter === [] ? 0.0 : max($fillAfter),
            'unplaced_tokens' => (float) array_sum($plan['overflow']),
            'safety_margin_percent' => (int) config('token_slayer.rebalance.safety_margin_percent'),
            'window_label' => $reading->window->label(),
            'simulated_accounts' => max(0, $extraAccounts),
            'capacity_tokens' => (float) array_sum($capacities),
        ];
    }

    /**
     * Dress each planned move up with the measurements behind it.
     *
     * @param  array<int, array{user: array-key, from: int, to: int, swap_with: array-key|null}>  $moves  the planner's raw diff
     * @param  array<int, array{weekly: float, per_day: float, trailing_avg_per_day: float, peak_week_tokens: float, basis: string, days_of_history: int, quota_weight: float, quota_weight_windows: int, quota_weight_clamped: bool}>  $details  per-user demand workings
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

            $short = $this->shortOfHistory($userId, $move, $userDays, $accountDays, $minDays, $accounts);

            $recommendations[] = new RebalanceRecommendation(
                userId: $userId,
                fromAccountId: $move['from'],
                toAccountId: $move['to'],
                swapWithUserId: $move['swap_with'] === null ? null : (int) $move['swap_with'],
                demandWeeklyTokens: $detail['weekly'] * $detail['quota_weight'],
                demandPerDayTokens: $detail['per_day'] * $detail['quota_weight'],
                demandBasis: $detail['basis'],
                trailingAvgPerDayTokens: $detail['trailing_avg_per_day'],
                peakWeekTokens: $detail['peak_week_tokens'],
                burstFactor: $burstFactors[$userId] ?? 1.0,
                quotaWeight: $detail['quota_weight'],
                quotaWeightWindows: $detail['quota_weight_windows'],
                quotaWeightClamped: $detail['quota_weight_clamped'] ?? false,
                daysOfHistory: $detail['days_of_history'],
                fromFillBeforePercent: $fillBefore[$move['from']] ?? 0.0,
                fromFillAfterPercent: $fillAfter[$move['from']] ?? 0.0,
                toFillBeforePercent: $fillBefore[$move['to']] ?? 0.0,
                toFillAfterPercent: $fillAfter[$move['to']] ?? 0.0,
                confident: $short === null,
                confidenceReason: $short,
            );
        }

        return $recommendations;
    }

    /**
     * Which of the three subjects a move rests on has too little recorded
     * history, said plainly, or null when all of them are established.
     *
     * Reporting only "not enough data" put the doubt on whoever the row
     * named, and an admin would read it beside that person's own perfectly
     * long history and conclude the page was wrong. Usually it is the
     * destination account — a newly bought one has days of history while
     * everybody involved has months.
     *
     * An account that has not been bought yet is not judged at all. It can
     * never have history, so the warning fired on every row pointing at it,
     * spending the page's one doubt signal on something that can never be
     * otherwise — and leaking the negative id it is held under while doing
     * it.
     *
     * @param  int  $userId  the person moving
     * @param  array{user: array-key, from: int, to: int, swap_with: array-key|null}  $move  the planned move
     * @param  array<int, int>  $userDays  user id => days of recorded history
     * @param  array<int, int>  $accountDays  account id => days of recorded history
     * @param  int  $minDays  the minimum to be trusted
     * @param  Collection<int, Account>  $accounts  the accounts in scope, for their labels
     * @return string|null
     */
    private function shortOfHistory(int $userId, array $move, array $userDays, array $accountDays, int $minDays, Collection $accounts): ?string
    {
        $labels = $accounts->pluck('email', 'id')->all();

        foreach ([$move['to'], $move['from']] as $accountId) {
            if ($accountId < 0) {
                continue; // hypothetical: it cannot have history, and saying so is not a finding
            }

            $days = $accountDays[$accountId] ?? 0;
            if ($days < $minDays) {
                return sprintf('%s has only %d %s of recorded history, under the %d needed.',
                    $labels[$accountId] ?? "#{$accountId}", $days, $days === 1 ? 'day' : 'days', $minDays);
            }
        }

        $days = $userDays[$userId] ?? 0;

        return $days < $minDays
            ? sprintf('This member has only %d %s of recorded history, under the %d needed.', $days, $days === 1 ? 'day' : 'days', $minDays)
            : null;
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
     * @param  array<int, array{basis: string, windows: int}>  $capacityBasis  account id => how its capacity was arrived at
     * @return array<int, array{id: int, email: string, is_new: bool, capacity_basis: string, capacity_tokens: float, fill_before_percent: float, fill_after_percent: float, members_before: int, members_after: int}>
     */
    private function accountSummaries(
        Collection $accounts,
        array $capacities,
        array $fillBefore,
        array $fillAfter,
        array $current,
        array $assignment,
        array $capacityBasis = [],
    ): array {
        $before = array_count_values($current);
        $after = array_count_values($assignment);

        $summaries = [];

        foreach ($capacities as $accountId => $capacity) {
            if ($accountId >= 0) {
                continue;
            }

            $summaries[$accountId] = [
                'id' => $accountId,
                'email' => 'New account '.abs($accountId),
                'is_new' => true,
                'capacity_basis' => 'assumed',
                'capacity_tokens' => $capacity,
                'fill_before_percent' => 0.0,
                'fill_after_percent' => $fillAfter[$accountId] ?? 0.0,
                'members_before' => 0,
                'members_after' => $after[$accountId] ?? 0,
            ];
        }

        foreach ($accounts as $account) {
            if (! array_key_exists($account->id, $capacities)) {
                continue; // nothing measurable to report about it yet
            }

            $summaries[$account->id] = [
                'id' => $account->id,
                'is_new' => false,
                'capacity_basis' => $capacityBasis[$account->id]['basis'] ?? 'unknown',
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
