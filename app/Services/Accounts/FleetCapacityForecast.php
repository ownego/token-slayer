<?php

namespace App\Services\Accounts;

use App\Models\User;

/**
 * Answers the question rebalancing cannot: is there simply not enough fleet,
 * and if we bought another account, who would end up on it?
 *
 * It reports two demands rather than one, because they lead to very
 * different purchases. The worst case — every person's heaviest week landing
 * in the same week — is what an account has to survive to never throttle
 * anyone; the typical case is the week the fleet actually lives in. On the
 * real fleet those differ by a factor of nearly two, and quoting only the
 * worst case would have recommended buying five accounts the team does not
 * need.
 */
final class FleetCapacityForecast
{
    /**
     * @param  FleetSnapshot  $snapshot  reads the fleet's capacities, memberships and demands
     * @param  RebalancePlanner  $planner  works out where people would sit given a set of accounts
     */
    public function __construct(
        private readonly FleetSnapshot $snapshot,
        private readonly RebalancePlanner $planner,
    ) {}

    /**
     * Size the fleet, and optionally simulate buying `$extraAccounts` more.
     *
     * @param  RebalanceWindow|null  $window  how far back to read, defaulting to the configured trend window
     * @param  int  $extraAccounts  how many hypothetical accounts to add
     * @return array{assumed_capacity_tokens: float, capacity_tokens: float, usable_tokens: float, safety_margin_percent: int, window_label: string, typical: array{tokens: float, percent: float, accounts_needed: int}, worst_case: array{tokens: float, percent: float, accounts_needed: int}, projection: array{extra_accounts: int, peak_fill_percent: float, overflow_tokens: float, accounts: array<int, array{label: string, is_new: bool, capacity_tokens: float, fill_percent: float, members: int}>, arrivals: array<int, array{user_id: int, user_label: string, account_label: string, weekly_tokens: float}>}|null}
     */
    public function forecast(?RebalanceWindow $window = null, int $extraAccounts = 0): array
    {
        $reading = $this->snapshot->take($window ?? RebalanceWindow::fromFilter(null));

        $capacity = (float) array_sum($reading->capacities);
        $median = $reading->medianCapacity();
        $worstCase = array_sum($reading->demands);
        $typical = array_sum($reading->typicalDemands);

        return [
            'assumed_capacity_tokens' => $median,
            'capacity_tokens' => $capacity,
            'usable_tokens' => $reading->usableTokens(),
            'safety_margin_percent' => (int) config('token_slayer.rebalance.safety_margin_percent'),
            'window_label' => $reading->window->label(),
            'typical' => $this->sizing($typical, $capacity, $median, $reading->safetyMargin()),
            'worst_case' => $this->sizing($worstCase, $capacity, $median, $reading->safetyMargin()),
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
}
