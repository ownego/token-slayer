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
 * pair's heaviest week (700,000) overflows it while their average week
 * (163,333 across the 30-day range) sits comfortably inside.
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

    // The distinction the whole feature turns on: buying capacity for the
    // worst case means buying for a week in which every single person
    // simultaneously has their heaviest week, which is not the week the
    // fleet actually lives in.
    expect(round($forecast['typical']['percent'], 1))->toBe(26.3)
        ->and($forecast['typical']['accounts_needed'])->toBe(0)
        ->and(round($forecast['worst_case']['percent'], 1))->toBe(112.5)
        ->and($forecast['worst_case']['accounts_needed'])->toBe(1);

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

it('shows who would move onto a new account and what that leaves behind', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    ['light' => $light] = strainedFleet();

    $forecast = app(FleetCapacityForecast::class)->forecast(RebalanceWindow::days(30), extraAccounts: 1);

    expect($forecast['projection'])->not->toBeNull()
        ->and($forecast['projection']['accounts'])->toHaveCount(2)
        // The lighter member moves: both arrangements relieve the account
        // equally, so the one disturbing fewer people wins.
        ->and($forecast['projection']['arrivals'])->toHaveCount(1)
        ->and($forecast['projection']['arrivals'][0]['user_id'])->toBe($light->id)
        // 700,000 over two accounts of 622,222 usable: nothing left over.
        ->and($forecast['projection']['overflow_tokens'])->toBe(0.0)
        ->and($forecast['projection']['peak_fill_percent'])->toBeLessThan(90.0);

    Carbon::setTestNow();
});

it('reports no projection at all when asked about adding nothing', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    strainedFleet();

    $forecast = app(FleetCapacityForecast::class)->forecast(RebalanceWindow::days(30));

    expect($forecast['projection'])->toBeNull();

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
