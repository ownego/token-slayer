<?php

namespace App\Services\Accounts;

use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
     */
    public function __construct(
        private readonly AccountCapacityEstimator $capacity,
        private readonly UserDemandEstimator $demand,
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

        $capacities = array_filter(
            $this->capacity->capacitiesFor($accounts, $window),
            fn (?float $tokens): bool => $tokens !== null && $tokens > 0.0,
        );

        $current = $this->currentMemberships($accounts, $window);
        $users = User::query()->whereIn('id', array_keys($current))->get()->keyBy('id');
        $weights = $this->demand->quotaWeights($accounts, $window);

        $demands = [];
        $typicalDemands = [];
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
            $typicalDemands[$userId] = $measured['trailing_avg_per_day'] * 7 * $weight;
            $burstFactors[$userId] = $this->demand->burstFactor($user, $window);
            $details[$userId] = $measured + ['quota_weight' => $weight];
        }

        return new FleetReading(
            accounts: $accounts,
            capacities: $capacities,
            current: array_intersect_key($current, $demands),
            demands: $demands,
            typicalDemands: $typicalDemands,
            burstFactors: $burstFactors,
            details: $details,
            window: $window,
        );
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
}
