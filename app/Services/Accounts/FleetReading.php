<?php

namespace App\Services\Accounts;

use App\Models\Account;
use Illuminate\Support\Collection;

/**
 * One coherent set of measurements of the fleet, all read over the same
 * range.
 *
 * Passed around as a unit because the figures are only meaningful together:
 * a capacity measured over a month and a demand measured over a week cannot
 * be compared, and nothing downstream should have to check that they match.
 */
final readonly class FleetReading
{
    /**
     * @param  Collection<int, Account>  $accounts  every connected account in scope
     * @param  array<int, float>  $capacities  account id => weekly capacity in tokens, measurable accounts only
     * @param  array<int, int>  $current  user id => the account they sit on today
     * @param  array<int, float>  $demands  user id => quota-weighted heaviest week in tokens
     * @param  array<int, float>  $typicalDemands  user id => quota-weighted average week in tokens
     * @param  array<int, float>  $burstFactors  user id => busiest hour over mean hour
     * @param  array<int, array{weekly: float, per_day: float, trailing_avg_per_day: float, peak_week_tokens: float, basis: string, days_of_history: int, quota_weight: float}>  $details  user id => the workings behind their demand
     * @param  RebalanceWindow  $window  the range every figure above was read over
     */
    public function __construct(
        public Collection $accounts,
        public array $capacities,
        public array $current,
        public array $demands,
        public array $typicalDemands,
        public array $burstFactors,
        public array $details,
        public RebalanceWindow $window,
    ) {}

    /**
     * The fraction of every account's capacity left unplanned, so an account
     * planned to the brim on paper still has somewhere to go on a bad day.
     *
     * @return float
     */
    public function safetyMargin(): float
    {
        return ((int) config('token_slayer.rebalance.safety_margin_percent')) / 100;
    }

    /**
     * Tokens the fleet can actually be planned to consume in a week, after
     * the safety margin.
     *
     * @return float
     */
    public function usableTokens(): float
    {
        return array_sum($this->capacities) * (1 - $this->safetyMargin());
    }

    /**
     * The capacity of a middling account in this fleet — what a newly bought
     * one can be assumed to be worth, since nothing is yet known about it.
     *
     * @return float
     */
    public function medianCapacity(): float
    {
        $values = array_values($this->capacities);
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
