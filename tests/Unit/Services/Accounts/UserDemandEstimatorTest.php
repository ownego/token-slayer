<?php

use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use App\Models\Event;
use App\Models\User;
use App\Services\Accounts\RebalanceWindow;
use App\Services\Accounts\UserDemandEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('takes the heaviest week on record when a user throttled themselves afterwards', function () {
    Carbon::setTestNow('2026-09-12 00:00:00');
    $account = Account::factory()->connected()->create();
    $user = User::factory()->create();

    // Two 40,000-token days ten days ago, silence since: spread across the
    // whole 30-day range that averages out to almost nothing, but this
    // person demonstrably wants 80,000 in a week they are not holding back.
    Event::factory()->for($account)->for($user)->create(['tokens' => 40_000, 'created_at' => now()->subDays(10)]);
    Event::factory()->for($account)->for($user)->create(['tokens' => 40_000, 'created_at' => now()->subDays(9)]);

    $demand = app(UserDemandEstimator::class)->demandFor($user, RebalanceWindow::days(30));

    // The figure is a WEEK's worth, measured over a week -- never a peak
    // day scaled up by seven, which assumes a person sustains their worst
    // day for a full week and inflates a real fleet by an order of
    // magnitude against a weekly quota measured the honest way.
    expect($demand['weekly'])->toBe(80_000.0)
        ->and($demand['basis'])->toBe('peak_week')
        ->and($demand['peak_week_tokens'])->toBe(80_000.0)
        ->and($demand['per_day'])->toBe(80_000.0 / 7);

    Carbon::setTestNow();
});

it('reads the same figure either way when usage never varies', function () {
    Carbon::setTestNow('2026-09-12 00:00:00');
    $account = Account::factory()->connected()->create();
    $user = User::factory()->create();

    foreach (range(1, 10) as $daysAgo) {
        Event::factory()->for($account)->for($user)->create(['tokens' => 10_000, 'created_at' => now()->subDays($daysAgo)]);
    }

    $demand = app(UserDemandEstimator::class)->demandFor($user, RebalanceWindow::days(10));

    // Ten steady days: the heaviest week and the average week agree, and
    // the label must say the average is what decided it rather than
    // implying a burst that never happened.
    expect($demand['weekly'])->toBe(70_000.0)
        ->and($demand['basis'])->toBe('trailing_average')
        ->and($demand['trailing_avg_per_day'])->toBe(10_000.0);

    Carbon::setTestNow();
});

it('counts a gap day as a zero rather than closing the gap up', function () {
    Carbon::setTestNow('2026-09-12 00:00:00');
    $account = Account::factory()->connected()->create();
    $user = User::factory()->create();

    // 50,000 today and 50,000 twenty days ago. Treating the two recorded
    // days as adjacent would read as a 100,000-token week; they are three
    // weeks apart, and no week ever held more than 50,000.
    Event::factory()->for($account)->for($user)->create(['tokens' => 50_000, 'created_at' => now()->subDays(20)]);
    Event::factory()->for($account)->for($user)->create(['tokens' => 50_000, 'created_at' => now()->subDay()]);

    $demand = app(UserDemandEstimator::class)->demandFor($user, RebalanceWindow::days(30));

    expect($demand['peak_week_tokens'])->toBe(50_000.0);

    Carbon::setTestNow();
});

it('counts a user\'s usage across every account, since demand travels with the person', function () {
    Carbon::setTestNow('2026-09-12 00:00:00');
    $accountA = Account::factory()->connected()->create();
    $accountB = Account::factory()->connected()->create();
    $user = User::factory()->create();

    Event::factory()->for($accountA)->for($user)->create(['tokens' => 7_000, 'created_at' => now()->subDays(2)]);
    Event::factory()->for($accountB)->for($user)->create(['tokens' => 7_000, 'created_at' => now()->subDays(2)]);

    $demand = app(UserDemandEstimator::class)->demandFor($user, RebalanceWindow::days(7));

    // 14,000 in the one week they were active is what a move has to carry.
    expect($demand['peak_week_tokens'])->toBe(14_000.0);

    Carbon::setTestNow();
});

it('reports how many days of history back the demand figure', function () {
    Carbon::setTestNow('2026-09-12 00:00:00');
    $account = Account::factory()->connected()->create();
    $user = User::factory()->create();
    Event::factory()->for($account)->for($user)->create(['tokens' => 100, 'created_at' => now()->subDays(9)]);
    Event::factory()->for($account)->for($user)->create(['tokens' => 100, 'created_at' => now()->subDay()]);

    $demand = app(UserDemandEstimator::class)->demandFor($user, RebalanceWindow::days(30));

    expect($demand['days_of_history'])->toBe(9);

    Carbon::setTestNow();
});

it('measures how clustered a user\'s day is, for the 5h-window risk it implies', function () {
    Carbon::setTestNow('2026-09-12 12:00:00');
    $account = Account::factory()->connected()->create();
    $user = User::factory()->create();

    // One 8,000-token hour and one 2,000-token hour: peak is 1.6x the mean.
    Event::factory()->for($account)->for($user)->create(['tokens' => 8_000, 'created_at' => now()->subHours(2)]);
    Event::factory()->for($account)->for($user)->create(['tokens' => 2_000, 'created_at' => now()->subHours(26)]);

    expect(app(UserDemandEstimator::class)->burstFactor($user, RebalanceWindow::days(14)))->toBe(1.6);

    Carbon::setTestNow();
});

it('reports a flat burst factor for a user with no usage at all', function () {
    $user = User::factory()->create();

    expect(app(UserDemandEstimator::class)->burstFactor($user, RebalanceWindow::days(14)))->toBe(1.0);
});

it('weighs a user who burns quota faster per token heavier than their account peers', function () {
    Carbon::setTestNow('2026-09-12 12:00:00');
    $account = Account::factory()->connected()->create();
    $heavyMix = User::factory()->create();
    $lightMix = User::factory()->create();

    // Window 1: heavyMix alone moves util_5h 10 -> 40 (30 points) on 3,000
    // tokens -> 100 tokens per point.
    $resetOne = now()->addHours(3);
    AccountUsageSnapshot::factory()->for($account)->create(['util_5h' => 10, 'reset_5h_at' => $resetOne, 'created_at' => now()->subMinutes(40)]);
    AccountUsageSnapshot::factory()->for($account)->create(['util_5h' => 40, 'reset_5h_at' => $resetOne, 'created_at' => now()->subMinutes(30)]);
    Event::factory()->for($account)->for($heavyMix)->create(['tokens' => 3_000, 'created_at' => now()->subMinutes(35)]);

    // Window 2: lightMix alone moves util_5h 10 -> 40 but needs 9,000
    // tokens -> 300 tokens per point, i.e. three times cheaper per token.
    $resetTwo = now()->addHours(9);
    AccountUsageSnapshot::factory()->for($account)->create(['util_5h' => 10, 'reset_5h_at' => $resetTwo, 'created_at' => now()->subMinutes(20)]);
    AccountUsageSnapshot::factory()->for($account)->create(['util_5h' => 40, 'reset_5h_at' => $resetTwo, 'created_at' => now()->subMinutes(10)]);
    Event::factory()->for($account)->for($lightMix)->create(['tokens' => 9_000, 'created_at' => now()->subMinutes(15)]);

    $weights = app(UserDemandEstimator::class)->quotaWeights(collect([$account]), RebalanceWindow::days(7));

    // Account median is 200 tokens/point. heavyMix needs only 100 -> each of
    // their tokens costs twice the typical quota -> weight 2. lightMix needs
    // 300 -> weight 0.67.
    expect(round($weights[$heavyMix->id], 2))->toBe(2.0)
        ->and(round($weights[$lightMix->id], 2))->toBe(0.67);

    Carbon::setTestNow();
});

it('leaves a user unweighted when no window had them using an account alone', function () {
    Carbon::setTestNow('2026-09-12 12:00:00');
    $account = Account::factory()->connected()->create();
    $user = User::factory()->create();
    $other = User::factory()->create();
    $resetAt = now()->addHours(3);

    AccountUsageSnapshot::factory()->for($account)->create(['util_5h' => 10, 'reset_5h_at' => $resetAt, 'created_at' => now()->subMinutes(30)]);
    AccountUsageSnapshot::factory()->for($account)->create(['util_5h' => 40, 'reset_5h_at' => $resetAt, 'created_at' => now()]);
    Event::factory()->for($account)->for($user)->create(['tokens' => 3_000, 'created_at' => now()->subMinutes(20)]);
    Event::factory()->for($account)->for($other)->create(['tokens' => 1_000, 'created_at' => now()->subMinutes(15)]);

    $weights = app(UserDemandEstimator::class)->quotaWeights(collect([$account]), RebalanceWindow::days(7));

    expect($weights)->toBe([]);

    Carbon::setTestNow();
});
