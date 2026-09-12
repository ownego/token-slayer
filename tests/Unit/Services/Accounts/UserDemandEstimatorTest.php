<?php

use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use App\Models\Event;
use App\Models\User;
use App\Services\Accounts\UserDemandEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('uses the peak 2-day average over the trailing average when a user self-throttled after a burst', function () {
    Carbon::setTestNow('2026-09-12 00:00:00');
    $account = Account::factory()->create();
    $user = User::factory()->create();

    // Days 1-2 (10 days ago): 40,000 tokens/day burst. Days after: silent
    // (self-throttled). Trailing-7d average = 0 (nothing in the last 7
    // days); peak 2-day average = 40,000. baseline must pick the peak.
    Event::factory()->for($account)->for($user)->create(['tokens' => 40_000, 'created_at' => now()->subDays(10)]);
    Event::factory()->for($account)->for($user)->create(['tokens' => 40_000, 'created_at' => now()->subDays(9)]);

    expect(app(UserDemandEstimator::class)->baselineTokensPerDay($user, $account))->toBe(40_000.0);

    Carbon::setTestNow();
});

it('uses the trailing-7d average when it exceeds the peak window (steady, not bursty, usage)', function () {
    Carbon::setTestNow('2026-09-12 00:00:00');
    $account = Account::factory()->create();
    $user = User::factory()->create();

    foreach (range(1, 7) as $daysAgo) {
        Event::factory()->for($account)->for($user)->create(['tokens' => 10_000, 'created_at' => now()->subDays($daysAgo)]);
    }

    expect(app(UserDemandEstimator::class)->baselineTokensPerDay($user, $account))->toBe(10_000.0);

    Carbon::setTestNow();
});

it('returns a burst factor of peak-hour over average-hour tokens', function () {
    Carbon::setTestNow('2026-09-12 12:00:00');
    $account = Account::factory()->create();
    $user = User::factory()->create();

    // One heavy hour (8,000) and one quiet hour (2,000) => avg 5,000, peak 8,000, factor 1.6.
    Event::factory()->for($account)->for($user)->create(['tokens' => 8_000, 'created_at' => now()->subHours(2)]);
    Event::factory()->for($account)->for($user)->create(['tokens' => 2_000, 'created_at' => now()->subHours(26)]);

    expect(app(UserDemandEstimator::class)->burstFactor($user, $account))->toBe(1.6);

    Carbon::setTestNow();
});

it('returns a burst factor of 1.0 when the user has no events in the trend window', function () {
    $account = Account::factory()->create();
    $user = User::factory()->create();

    expect(app(UserDemandEstimator::class)->burstFactor($user, $account))->toBe(1.0);
});

it('measures a user\'s own quota-cost-per-token from a 5h window where they were the only contributor', function () {
    Carbon::setTestNow('2026-09-12 12:00:00');
    $account = Account::factory()->create();
    $user = User::factory()->create();
    $resetAt = now()->addHours(3);

    // A clean window: util_5h climbs 10 -> 40 (delta 30) while only $user
    // has events in it. They contributed 3,000 tokens, so their measured
    // ratio is 30/3000 = 0.01 percent-per-token, i.e. tokensPerPercent = 100.
    AccountUsageSnapshot::factory()->for($account)->create(['util_5h' => 10, 'reset_5h_at' => $resetAt, 'created_at' => now()->subMinutes(10)]);
    AccountUsageSnapshot::factory()->for($account)->create(['util_5h' => 40, 'reset_5h_at' => $resetAt, 'created_at' => now()]);
    Event::factory()->for($account)->for($user)->create(['tokens' => 3_000, 'created_at' => now()->subMinutes(5)]);

    expect(app(UserDemandEstimator::class)->cleanWindowQuotaWeight($user, $account))->toBe(100.0);

    Carbon::setTestNow();
});

it('returns null when the user never had a clean (single-contributor) window', function () {
    Carbon::setTestNow('2026-09-12 12:00:00');
    $account = Account::factory()->create();
    $user = User::factory()->create();
    $other = User::factory()->create();
    $resetAt = now()->addHours(3);

    AccountUsageSnapshot::factory()->for($account)->create(['util_5h' => 10, 'reset_5h_at' => $resetAt, 'created_at' => now()->subMinutes(10)]);
    AccountUsageSnapshot::factory()->for($account)->create(['util_5h' => 40, 'reset_5h_at' => $resetAt, 'created_at' => now()]);
    Event::factory()->for($account)->for($user)->create(['tokens' => 3_000, 'created_at' => now()->subMinutes(5)]);
    Event::factory()->for($account)->for($other)->create(['tokens' => 1_000, 'created_at' => now()->subMinutes(4)]);

    expect(app(UserDemandEstimator::class)->cleanWindowQuotaWeight($user, $account))->toBeNull();

    Carbon::setTestNow();
});
