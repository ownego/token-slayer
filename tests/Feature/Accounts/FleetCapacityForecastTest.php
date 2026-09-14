<?php

use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use App\Models\Event;
use App\Models\User;
use App\Services\Accounts\FleetCapacityForecast;
use App\Services\Accounts\RebalanceWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * A one-account fleet whose closed week peaked at 90% util on 700,000
 * tokens, shared by a heavy and a light member. Capacity therefore measures
 * 777,778/week, 622,222 of it plannable after the safety margin — so the
 * pair's heaviest week (700,000) busts the planning target while their
 * average week (163,333 across the 30-day range) sits far inside it.
 *
 * @return array{account: Account, heavy: User, light: User}
 */
function strainedFleet(): array
{
    $account = Account::factory()->connected()->create();

    AccountUsageSnapshot::factory()->for($account)->create([
        'util_7d' => 90,
        'reset_7d_at' => Carbon::parse('2026-09-12 00:00:00'),
        'created_at' => Carbon::parse('2026-09-11 23:00:00'),
    ]);

    $heavy = User::factory()->create();
    $light = User::factory()->create();

    foreach ([[$heavy, 60_000], [$light, 40_000]] as [$user, $tokensPerDay]) {
        foreach (range(1, 7) as $offset) {
            Event::factory()->for($account)->for($user)->create([
                'tokens' => $tokensPerDay,
                'created_at' => Carbon::parse('2026-09-04 12:00:00')->addDays($offset),
            ]);
        }

        $account->users()->syncWithoutDetaching([$user->id => ['status' => MembershipStatus::Tracked->value]]);
    }

    return ['account' => $account, 'heavy' => $heavy, 'light' => $light];
}

it('separates a fleet that fits on an average week from one that fits at its peak', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    strainedFleet();

    $forecast = app(FleetCapacityForecast::class)->forecast(RebalanceWindow::days(30));

    // Both members really did peak in the same week here, so the model and
    // the observation agree at 90% of a 777,778 capacity. What the test
    // pins is which one the verdict is taken FROM: on a real fleet the model
    // reads far heavier than anything that has ever happened, and sizing off
    // it buys accounts to fix a distribution problem.
    expect(round($forecast['observed']['fleet_peak_percent'], 1))->toBe(90.0)
        ->and($forecast['observed']['accounts_over_capacity'])->toBe(0)
        ->and($forecast['observed']['accounts_needed'])->toBe(1)
        ->and(round($forecast['worst_case']['percent'], 1))->toBe(90.0);

    Carbon::setTestNow();
});

it('sizes off the fleet\'s own busiest week even when the model reads far heavier', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    ['account' => $account] = strainedFleet();

    // A third member whose heavy week was a fortnight earlier. The model adds
    // their peak to the other two as though all three coincided; the fleet's
    // own busiest week never held more than two of them.
    $earlier = User::factory()->create();
    foreach (range(1, 7) as $offset) {
        Event::factory()->for($account)->for($earlier)->create([
            'tokens' => 60_000,
            'created_at' => Carbon::parse('2026-08-21 12:00:00')->addDays($offset),
        ]);
    }
    $account->users()->syncWithoutDetaching([$earlier->id => ['status' => MembershipStatus::Tracked->value]]);

    $forecast = app(FleetCapacityForecast::class)->forecast(RebalanceWindow::days(30));

    expect($forecast['observed']['fleet_peak_tokens'])->toBe(700_000.0)
        ->and($forecast['worst_case']['percent'])->toBeGreaterThan($forecast['observed']['fleet_peak_percent']);

    Carbon::setTestNow();
});

it('assumes a newly bought account is worth what a middling existing one is', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    strainedFleet();

    $forecast = app(FleetCapacityForecast::class)->forecast(RebalanceWindow::days(30));

    // Nothing is known about an account nobody has used yet, so the only
    // honest assumption is that it resembles the ones we can measure.
    expect(round($forecast['assumed_capacity_tokens']))->toBe(777_778.0);

    Carbon::setTestNow();
});

it('says a fleet with nothing measurable needs nothing rather than dividing by zero', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    Account::factory()->connected()->create();

    $forecast = app(FleetCapacityForecast::class)->forecast(RebalanceWindow::days(30));

    expect($forecast['worst_case']['accounts_needed'])->toBe(0)
        ->and($forecast['assumed_capacity_tokens'])->toBe(0.0);

    Carbon::setTestNow();
});
