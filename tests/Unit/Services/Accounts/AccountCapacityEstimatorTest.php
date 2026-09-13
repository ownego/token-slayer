<?php

use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use App\Models\Event;
use App\Models\User;
use App\Services\Accounts\AccountCapacityEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('derives tokensPerPercent from the account\'s own trailing-7d tokens and current util_7d', function () {
    Carbon::setTestNow('2026-09-12 12:00:00');
    $account = Account::factory()->create();
    AccountUsageSnapshot::factory()->for($account)->create(['util_7d' => 50, 'created_at' => now()]);
    Event::factory()->for($account)->for(User::factory())->create(['tokens' => 100_000, 'created_at' => now()->subDays(2)]);
    Event::factory()->for($account)->for(User::factory())->create(['tokens' => 50_000, 'created_at' => now()->subDays(10)]); // outside the 7d window

    expect(app(AccountCapacityEstimator::class)->tokensPerPercent($account))->toBe(2_000.0);

    Carbon::setTestNow();
});

it('returns 0 tokensPerPercent when the account has no usage snapshot yet', function () {
    $account = Account::factory()->create();

    expect(app(AccountCapacityEstimator::class)->tokensPerPercent($account))->toBe(0.0);
});

it('returns the raw util_7d unprojected when the latest snapshot has no reset_7d_at', function () {
    // Real staging data: a probed account can carry a snapshot with
    // util_7d/reset_7d_at both unset (e.g. a probe that returned no rate
    // limit window yet). There is no reset time to project toward, so the
    // safest answer is the raw reading, not a crash.
    $account = Account::factory()->create();
    AccountUsageSnapshot::factory()->for($account)->create(['util_7d' => 0, 'reset_7d_at' => null, 'created_at' => now()]);

    expect(app(AccountCapacityEstimator::class)->projectedUtilAtReset($account))->toBe(0);
});

it('projects util at reset using the peak single-day token rate, not the trailing average', function () {
    Carbon::setTestNow('2026-09-12 00:00:00');
    $account = Account::factory()->create();
    $resetAt = now()->addDays(4);
    AccountUsageSnapshot::factory()->for($account)->create([
        'util_7d' => 25, 'reset_7d_at' => $resetAt, 'created_at' => now(),
    ]);
    // trailing-7d tokens = 80,000 + 20,000 = 100,000, so tokensPerPercent =
    // 100,000 / 25 = 4,000. The peak SINGLE day is 80,000 tokens (not the
    // 100,000 trailing total), a rate of 80,000 / 4,000 = 20%/day.
    // projected = 25 + 20*4 = 105 -- a plain trailing-average rate
    // (100,000 tokens spread over 7 days) would give a much lower, and
    // wrong, "still safe" projection.
    Event::factory()->for($account)->for(User::factory())->create(['tokens' => 80_000, 'created_at' => now()->subDay()]);
    Event::factory()->for($account)->for(User::factory())->create(['tokens' => 20_000, 'created_at' => now()->subDays(3)]);

    expect(app(AccountCapacityEstimator::class)->projectedUtilAtReset($account))->toBe(105);

    Carbon::setTestNow();
});

it('computes overflowTokens as the token cost of the projection past 100, and 0 when under', function () {
    Carbon::setTestNow('2026-09-12 00:00:00');
    $account = Account::factory()->create();
    AccountUsageSnapshot::factory()->for($account)->create([
        'util_7d' => 40, 'reset_7d_at' => now()->addDays(4), 'created_at' => now(),
    ]);
    Event::factory()->for($account)->for(User::factory())->create(['tokens' => 80_000, 'created_at' => now()->subDay()]);

    $estimator = app(AccountCapacityEstimator::class);
    // Single event, so peak-day tokens equal the trailing-7d total:
    // tokensPerPercent = 80,000 / 40 = 2,000; rate = 80,000 / 2,000 = 40
    // (always equals util_7d when all trailing tokens land on one day).
    // projected = 40 + 40*4 = 200. overflow = (200 - 100) * 2,000 = 200,000.
    expect($estimator->overflowTokens($account))->toBe(200_000.0)
        ->and($estimator->headroomTokens($account))->toBe(0.0);

    Carbon::setTestNow();
});

it('computes headroomTokens as the token room left before 100 when projection stays under', function () {
    Carbon::setTestNow('2026-09-12 00:00:00');
    $account = Account::factory()->create();
    AccountUsageSnapshot::factory()->for($account)->create([
        'util_7d' => 10, 'reset_7d_at' => now()->addDays(4), 'created_at' => now(),
    ]);
    Event::factory()->for($account)->for(User::factory())->create(['tokens' => 5_000, 'created_at' => now()->subDay()]);

    $estimator = app(AccountCapacityEstimator::class);
    // Single event: tokensPerPercent = 5,000 / 10 = 500; rate = 5,000 / 500
    // = 10 (= util_7d, per the single-event identity above).
    // projected = 10 + 10*4 = 50. headroom = (100 - 50) * 500 = 25,000.
    expect($estimator->headroomTokens($account))->toBe(25_000.0)
        ->and($estimator->overflowTokens($account))->toBe(0.0);

    Carbon::setTestNow();
});
