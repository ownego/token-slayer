<?php

namespace App\Services\Accounts;

use App\Models\User;

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
     * @param  int  $extraAccounts  how many hypothetical accounts to add
     * @param  FleetReading|null  $reading  a reading already taken over that window, so a page showing both this and the rebalance table measures the fleet once
     * @return array{assumed_capacity_tokens: float, capacity_tokens: float, usable_tokens: float, safety_margin_percent: int, window_label: string, observed: array<string, mixed>, unconstrained: array<string, mixed>, worst_case: array{tokens: float, percent: float, accounts_needed: int}, projection: array{extra_accounts: int, peak_fill_percent: float, overflow_tokens: float, accounts: array<int, array{label: string, is_new: bool, capacity_tokens: float, fill_percent: float, members: int}>, arrivals: array<int, array{user_id: int, user_label: string, account_label: string, weekly_tokens: float}>}|null}
     */
    public function forecast(?RebalanceWindow $window = null, int $extraAccounts = 0, ?FleetReading $reading = null): array
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
            'projection' => $extraAccounts > 0 ? $this->project($reading, $extraAccounts) : null,
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
     * @return array{tokens: float, percent: float, accounts_needed: int}
     */
    private function sizing(float $demand, float $capacity, float $medianCapacity, float $safetyMargin): array
    {
        $perAccount = $medianCapacity * (1 - $safetyMargin);
        $shortfall = max(0.0, $demand - $capacity * (1 - $safetyMargin));

        return [
            'tokens' => $demand,
            'percent' => $capacity > 0.0 ? $demand * 100 / $capacity : 0.0,
            'accounts_needed' => $perAccount > 0.0 ? (int) ceil($shortfall / $perAccount) : 0,
        ];
    }

    /**
     * Where everyone would sit with `$extraAccounts` more accounts in the
     * fleet.
     *
     * The planner runs without its usual ten-move limit here: that limit
     * exists so an admin is handed a batch they can actually execute, and a
     * what-if has no admin to tire out. Cutting the simulation off at ten
     * would understate what the new accounts are worth.
     *
     * @param  FleetReading  $reading  the measured fleet
     * @param  int  $extraAccounts  how many hypothetical accounts to add
     * @return array{extra_accounts: int, peak_fill_percent: float, overflow_tokens: float, accounts: array<int, array{label: string, is_new: bool, capacity_tokens: float, fill_percent: float, members: int}>, arrivals: array<int, array{user_id: int, user_label: string, account_label: string, weekly_tokens: float}>}
     */
    private function project(FleetReading $reading, int $extraAccounts): array
    {
        $capacities = $reading->capacities;
        $labels = [];

        foreach ($reading->accounts as $account) {
            if (array_key_exists($account->id, $capacities)) {
                $labels[$account->id] = (string) $account->email;
            }
        }

        // Hypothetical accounts take negative ids: nothing else in the fleet
        // can collide with them, and anything that leaks one into a real
        // lookup fails loudly instead of silently hitting a real account.
        foreach (range(1, $extraAccounts) as $index) {
            $capacities[-$index] = $reading->medianCapacity();
            $labels[-$index] = "New account {$index}";
        }

        $plan = $this->planner->plan(
            capacities: $capacities,
            demands: $reading->demands,
            current: $reading->current,
            burstFactors: $reading->burstFactors,
            safetyMargin: $reading->safetyMargin(),
            maxMoves: max(1, count($reading->demands)),
        );

        $members = array_count_values($plan['assignment']);
        $accounts = [];
        $peak = 0.0;

        foreach ($capacities as $accountId => $capacity) {
            $fill = $capacity > 0.0 ? ($plan['fill_after'][$accountId] ?? 0.0) * 100 / $capacity : 0.0;
            $peak = max($peak, $fill);

            $accounts[] = [
                'label' => $labels[$accountId] ?? "#{$accountId}",
                'is_new' => $accountId < 0,
                'capacity_tokens' => $capacity,
                'fill_percent' => $fill,
                'members' => $members[$accountId] ?? 0,
            ];
        }

        return [
            'extra_accounts' => $extraAccounts,
            'peak_fill_percent' => $peak,
            'overflow_tokens' => (float) array_sum($plan['overflow']),
            'accounts' => $accounts,
            'arrivals' => $this->arrivals($plan['assignment'], $reading, $labels),
        ];
    }

    /**
     * The people who would end up on one of the hypothetical accounts —
     * the concrete half of the answer, since "buy another account" is only
     * actionable once you know who you would be asking to re-authenticate.
     *
     * @param  array<int, int>  $assignment  user id => account id under the projection
     * @param  FleetReading  $reading  the measured fleet
     * @param  array<int, string>  $labels  account id => display label
     * @return array<int, array{user_id: int, user_label: string, account_label: string, weekly_tokens: float}>
     */
    private function arrivals(array $assignment, FleetReading $reading, array $labels): array
    {
        $newcomerIds = array_keys(array_filter($assignment, fn (int $accountId): bool => $accountId < 0));
        if ($newcomerIds === []) {
            return [];
        }

        $users = User::query()->whereIn('id', $newcomerIds)->get()->keyBy('id');
        $arrivals = [];

        foreach ($newcomerIds as $userId) {
            $arrivals[] = [
                'user_id' => $userId,
                'user_label' => $users->get($userId)?->displayHandle() ?? "#{$userId}",
                'account_label' => $labels[$assignment[$userId]] ?? '',
                'weekly_tokens' => $reading->demands[$userId] ?? 0.0,
            ];
        }

        return $arrivals;
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
     * @return array{tokens: float, percent: float, accounts_saturated: int}
     */
    private function unconstrained(FleetReading $reading, array $observed): array
    {
        $ceilings = $this->suppressed->measure($reading->accounts, $reading->window);

        $tokens = 0.0;
        $saturated = 0;

        foreach ($reading->capacities as $accountId => $capacity) {
            $projected = $ceilings[$accountId]['projected_percent'] ?? null;
            $actual = $observed['per_account'][$accountId]['peak_tokens'] ?? 0.0;

            if ($projected === null) {
                $tokens += $actual;

                continue;
            }

            $saturated++;
            $tokens += max($actual, $capacity * $projected / 100);
        }

        $capacityTotal = (float) array_sum($reading->capacities);

        return [
            'tokens' => $tokens,
            'percent' => $capacityTotal > 0.0 ? $tokens * 100 / $capacityTotal : 0.0,
            'accounts_saturated' => $saturated,
        ];
    }
}
