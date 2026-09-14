<?php

use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use App\Services\Accounts\RebalanceWindow;
use App\Services\Accounts\SuppressedDemandEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * A closed 7-day quota window whose utilisation followed the given curve.
 *
 * @param  Account  $account  the account the window belongs to
 * @param  array<int, array{0: float, 1: int}>  $curve  [days since the window opened, util_7d then] pairs; a list rather than a map because PHP silently truncates a float array key to an int
 * @return void
 */
function quotaWindow(Account $account, array $curve): void
{
    $resetAt = Carbon::parse('2026-09-12 00:00:00');
    $opens = $resetAt->copy()->subDays(7);

    foreach ($curve as [$days, $util]) {
        AccountUsageSnapshot::factory()->for($account)->create([
            'util_7d' => $util,
            'reset_7d_at' => $resetAt,
            'created_at' => $opens->copy()->addMinutes((int) round($days * 1440)),
        ]);
    }
}

it('reads a week that ran out early as demand held back, not demand met', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    $account = Account::factory()->connected()->create();

    // 80% gone in three and a half days, then the curve flattens against the
    // ceiling and limps to 100%. The flat part is not moderation, it is
    // people who cannot spend what they wanted.
    quotaWindow($account, [[0, 0], [1, 23], [2, 46], [3.5, 80], [5, 95], [6.9, 100]]);

    $suppressed = app(SuppressedDemandEstimator::class)->measure(collect([$account]), RebalanceWindow::days(30));

    // 80% over 3.5 days is 22.9% a day; a full week at that rate is 160%.
    expect($suppressed[$account->id]['saturated'])->toBeTrue()
        ->and(round($suppressed[$account->id]['days_to_ramp'], 1))->toBe(3.5)
        ->and(round($suppressed[$account->id]['projected_percent']))->toBe(160.0);

    Carbon::setTestNow();
});

it('leaves a week that never ran out alone', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    $account = Account::factory()->connected()->create();

    // Never near the ceiling, so the usage is what people wanted and there
    // is nothing to correct. Inventing a correction here would inflate a
    // fleet that is behaving perfectly well.
    quotaWindow($account, [[0, 0], [2, 18], [4, 35], [6, 52], [6.9, 60]]);

    $suppressed = app(SuppressedDemandEstimator::class)->measure(collect([$account]), RebalanceWindow::days(30));

    expect($suppressed[$account->id]['saturated'])->toBeFalse()
        ->and($suppressed[$account->id]['projected_percent'])->toBeNull();

    Carbon::setTestNow();
});

it('does not read a steady week that merely ends full as suppressed', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    $account = Account::factory()->connected()->create();

    // A flat 14%-a-day burn that arrives at 100% exactly as the window
    // closes: the quota was sized correctly for this team, and the answer
    // must be ~100%, not an invented excess.
    quotaWindow($account, [[0, 0], [1, 14], [2, 29], [3, 43], [4, 57], [5, 71], [5.7, 80], [6.9, 97]]);

    $suppressed = app(SuppressedDemandEstimator::class)->measure(collect([$account]), RebalanceWindow::days(30));

    expect(round($suppressed[$account->id]['projected_percent']))->toBe(98.0);

    Carbon::setTestNow();
});

it('ignores a window still open, whose curve has not finished', function () {
    Carbon::setTestNow('2026-09-08 06:00:00');
    $account = Account::factory()->connected()->create();
    quotaWindow($account, [[0, 0], [1, 30], [2, 80]]);

    expect(app(SuppressedDemandEstimator::class)->measure(collect([$account]), RebalanceWindow::days(30)))
        ->toBe([]);

    Carbon::setTestNow();
});

it('separates an account that ran out from one that merely got close', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    $ranOut = Account::factory()->connected()->create();
    $nearMiss = Account::factory()->connected()->create();

    // On prod these two were reported together as "ran out mid-week and
    // rationed their own users", and one of them had never been past 82% of
    // its quota in a month. The ramp is where the rate stops being honest;
    // it is not where the account died, and a purchase argued from the wrong
    // one of those counts accounts that were never short.
    quotaWindow($ranOut, [[0, 0], [3.5, 80], [6.9, 100]]);
    quotaWindow($nearMiss, [[0, 0], [5, 80], [6.9, 82]]);

    $measured = app(SuppressedDemandEstimator::class)
        ->measure(collect([$ranOut, $nearMiss]), RebalanceWindow::days(30));

    expect($measured[$ranOut->id]['saturated'])->toBeTrue()
        ->and($measured[$ranOut->id]['ran_out'])->toBeTrue()
        ->and($measured[$nearMiss->id]['saturated'])->toBeTrue()
        ->and($measured[$nearMiss->id]['ran_out'])->toBeFalse();

    Carbon::setTestNow();
});

it('reads a boundary reported either side of the hour as one week, not two', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    $account = Account::factory()->connected()->create();

    $resetAt = Carbon::parse('2026-09-12 00:00:00');
    $opens = $resetAt->copy()->subDays(7);

    // One week whose reset the API reported a second early for part of the
    // run. Split in two, each half finds its own ramp and the estimator
    // medians two projections of a week that happened once -- here 196% and
    // 111% averaged into 153%, when the week plainly ramped on day three.
    foreach ([[1, 30], [3, 85]] as [$days, $util]) {
        AccountUsageSnapshot::factory()->for($account)->create([
            'util_7d' => $util,
            'reset_7d_at' => $resetAt->copy()->subSecond(),
            'created_at' => $opens->copy()->addMinutes((int) round($days * 1440)),
        ]);
    }
    foreach ([[6, 95]] as [$days, $util]) {
        AccountUsageSnapshot::factory()->for($account)->create([
            'util_7d' => $util,
            'reset_7d_at' => $resetAt,
            'created_at' => $opens->copy()->addMinutes((int) round($days * 1440)),
        ]);
    }

    $measured = app(SuppressedDemandEstimator::class)->measure(collect([$account]), RebalanceWindow::days(30));

    // One week, ramped on day three: 85 / 3 * 7 = 198.3.
    expect($measured[$account->id]['days_to_ramp'])->toBe(3.0)
        ->and(round($measured[$account->id]['projected_percent']))->toBe(198.0);

    Carbon::setTestNow();
});
