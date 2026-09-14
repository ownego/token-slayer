<?php

namespace App\Services\Accounts;

/**
 * Answers the question rebalancing cannot: is there simply not enough fleet,
 * and if we bought another account, who would end up on it?
 *
 * Three readings of the same fleet, because a purchase decision needs all
 * three and any one of them alone misleads:
 *
 * - what it actually got through, straight off the ledger;
 * - what it would have got through unthrottled, correcting the accounts that
 *   ran out mid-week and capped their own users;
 * - the model's ceiling, every person's heaviest week added together as
 *   though the peaks all landed at once.
 *
 * The middle one is the verdict. Spend alone understates a fleet that is
 * already rationing — the shortage censors the very measurement being used
 * to look for it — while the model's ceiling overstates it, because those
 * peaks have never all coincided.
 */
final class FleetCapacityForecast
{
    /**
     * @param  FleetSnapshot  $snapshot  reads the fleet's capacities, memberships and demands
     * @param  RebalancePlanner  $planner  works out where people would sit given a set of accounts
     * @param  ObservedFleetLoad  $observed  reads what the fleet actually did, so the model can be checked against it
     * @param  SuppressedDemandEstimator  $suppressed  reads how much more each account would have served without its ceiling
     */
    public function __construct(
        private readonly FleetSnapshot $snapshot,
        private readonly RebalancePlanner $planner,
        private readonly ObservedFleetLoad $observed,
        private readonly SuppressedDemandEstimator $suppressed,
    ) {}

    /**
     * Size the fleet, and optionally simulate buying `$extraAccounts` more.
     *
     * @param  RebalanceWindow|null  $window  how far back to read, defaulting to the configured trend window
     * @param  FleetReading|null  $reading  a reading already taken over that window, so a page showing both this and the rebalance table measures the fleet once
     * @return array{assumed_capacity_tokens: float, capacity_tokens: float, usable_tokens: float, safety_margin_percent: int, window_label: string, observed: array<string, mixed>, unconstrained: array<string, mixed>, worst_case: array{tokens: float, percent: float, accounts_needed: int, accounts_to_fit: int}}
     */
    public function forecast(?RebalanceWindow $window = null, ?FleetReading $reading = null): array
    {
        $reading ??= $this->snapshot->take($window ?? RebalanceWindow::fromFilter(null));

        $capacity = (float) array_sum($reading->capacities);
        $median = $reading->medianCapacity();
        $margin = $reading->safetyMargin();

        $observed = $this->observed->measure($reading->accounts, $reading->capacities, $reading->window);
        $observed['accounts_needed'] = $this->sizing($observed['fleet_peak_tokens'], $capacity, $median, $margin)['accounts_needed'];

        $unconstrained = $this->unconstrained($reading, $observed);
        $unconstrained += $this->sizing($unconstrained['tokens'], $capacity, $median, $margin);

        return [
            'assumed_capacity_tokens' => $median,
            'capacity_tokens' => $capacity,
            'usable_tokens' => $reading->usableTokens(),
            'safety_margin_percent' => (int) config('token_slayer.rebalance.safety_margin_percent'),
            'window_label' => $reading->window->label(),
            'observed' => $observed,
            'unconstrained' => $unconstrained,
            'worst_case' => $this->sizing(array_sum($reading->demands), $capacity, $median, $margin),
        ];
    }

    /**
     * How one demand figure sits against the fleet, and how many typical
     * accounts would close the gap.
     *
     * The percentage is of full capacity — the same denominator as every
     * other fill figure on the page — while the account count is what it
     * takes to get back under the planning target, since buying up to the
     * brim is what left the fleet with no slack in the first place.
     *
     * @param  float  $demand  weekly tokens the fleet is being sized for
     * @param  float  $capacity  the fleet's full weekly capacity in tokens
     * @param  float  $medianCapacity  what a bought account is assumed to be worth
     * @param  float  $safetyMargin  fraction of a capacity left unplanned
     * @return array{tokens: float, percent: float, accounts_needed: int, accounts_to_fit: int}
     */
    private function sizing(float $demand, float $capacity, float $medianCapacity, float $safetyMargin): array
    {
        $withSlack = $medianCapacity * (1 - $safetyMargin);
        $shortfallWithSlack = max(0.0, $demand - $capacity * (1 - $safetyMargin));
        $shortfallBare = max(0.0, $demand - $capacity);

        return [
            'tokens' => $demand,
            'percent' => $capacity > 0.0 ? $demand * 100 / $capacity : 0.0,
            'accounts_needed' => $withSlack > 0.0 ? (int) ceil($shortfallWithSlack / $withSlack) : 0,
            // Merely fitting and fitting comfortably are different purchases,
            // and an admin deciding what to buy needs to see both.
            'accounts_to_fit' => $medianCapacity > 0.0 ? (int) ceil($shortfallBare / $medianCapacity) : 0,
        ];
    }

    /**
     * What the fleet would have got through with nothing standing in its
     * way, and how many of its accounts were standing in the way.
     *
     * An account that ran out mid-week contributes the week its own burn
     * rate was heading for rather than the capped total it managed; one that
     * never approached its ceiling contributes what it actually did, because
     * for that account the two are the same thing.
     *
     * This is the figure a purchase should rest on. What the fleet spent is
     * censored by the very shortage being asked about — reading it as demand
     * is how a fleet that throttles its team every week comes out looking
     * comfortable.
     *
     * @param  FleetReading  $reading  the measured fleet
     * @param  array<string, mixed>  $observed  what the fleet actually got through
     * @return array{tokens: float, percent: float, accounts_saturated: int, accounts_ran_out: int}
     */
    private function unconstrained(FleetReading $reading, array $observed): array
    {
        $ceilings = $this->suppressed->measure($reading->accounts, $reading->window);

        $tokens = 0.0;
        $saturated = 0;
        $ranOut = 0;

        foreach ($reading->capacities as $accountId => $capacity) {
            $projected = $ceilings[$accountId]['projected_percent'] ?? null;
            $actual = $observed['per_account'][$accountId]['peak_tokens'] ?? 0.0;

            if ($projected === null) {
                $tokens += $actual;

                continue;
            }

            $saturated++;
            if ($ceilings[$accountId]['ran_out'] ?? false) {
                $ranOut++;
            }
            $tokens += max($actual, $capacity * $projected / 100);
        }

        $capacityTotal = (float) array_sum($reading->capacities);

        return [
            'tokens' => $tokens,
            'percent' => $capacityTotal > 0.0 ? $tokens * 100 / $capacityTotal : 0.0,
            'accounts_saturated' => $saturated,
            // Crossing the ramp and running dry are different events, and
            // only the second one rationed anybody. Counting them together
            // told an admin six of seven accounts had run out when one of
            // them had never been past 82% of its quota in a month.
            'accounts_ran_out' => $ranOut,
        ];
    }
}
