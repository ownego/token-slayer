<?php

namespace App\Services\Accounts;

use App\Models\Account;
use App\Models\User;

/**
 * Reads the whole fleet once — who is on what, how big each account is, and
 * how much each person wants — so the recommender and the capacity forecast
 * work from identical numbers.
 *
 * Both questions ("how should we rearrange?" and "would another account
 * help?") rest on the same measurements, and two copies of this gathering
 * would sooner or later answer them differently.
 */
final class FleetSnapshot
{
    /**
     * @param  AccountCapacityEstimator  $capacity  measures how many tokens each account's weekly quota is worth
     * @param  UserDemandEstimator  $demand  measures each person's appetite and how heavily their tokens bite
     * @param  HomeAccountResolver  $homes  decides which single account each person actually works on now
     */
    public function __construct(
        private readonly AccountCapacityEstimator $capacity,
        private readonly UserDemandEstimator $demand,
        private readonly HomeAccountResolver $homes,
    ) {}

    /**
     * Measure the fleet over `$window`.
     *
     * @param  RebalanceWindow  $window  how far back to read
     * @return FleetReading
     */
    public function take(RebalanceWindow $window): FleetReading
    {
        $accounts = Account::query()
            ->whereHas('claudeCredential', fn ($query) => $query->whereNotNull('organization_uuid'))
            ->with('claudeCredential')
            ->get();

        $resolved = array_filter(
            $this->capacity->capacitiesFor($accounts, $window),
            fn (array $entry): bool => $entry['tokens'] !== null && $entry['tokens'] > 0.0,
        );
        $capacities = array_map(fn (array $entry): float => $entry['tokens'], $resolved);
        $capacityBasis = array_map(fn (array $entry): array => ['basis' => $entry['basis'], 'windows' => $entry['windows']], $resolved);

        $current = $this->homes->resolve($accounts, $window);
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
            $weight = $weights[$userId]['weight'] ?? 1.0;
            $weekly = $measured['weekly'] * $weight;
            if ($weekly <= 0.0) {
                continue; // nobody to plan around; leave them where they are
            }

            $demands[$userId] = $weekly;
            $burstFactors[$userId] = $this->demand->burstFactor($user, $window);
            $details[$userId] = $measured + [
                'quota_weight' => $weight,
                'quota_weight_windows' => $weights[$userId]['windows'] ?? 0,
            ];
        }

        return new FleetReading(
            accounts: $accounts,
            capacities: $capacities,
            capacityBasis: $capacityBasis,
            current: array_intersect_key($current, $demands),
            demands: $demands,
            burstFactors: $burstFactors,
            details: $details,
            window: $window,
        );
    }
}
