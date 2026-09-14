<?php

use App\Enums\AccountPlan;
use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use App\Models\Event;
use App\Models\User;
use App\Services\Accounts\AccountCapacityEstimator;
use App\Services\Accounts\RebalanceWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * Record one completed 7-day quota window: snapshots carrying its reset time
 * and the peak utilization reached, plus the usage that produced it.
 *
 * @param  Account  $account  the account the window belongs to
 * @param  Carbon  $resetAt  when the window closed (must be in the past)
 * @param  int  $peakUtil  the highest util_7d observed during the window
 * @param  int  $tokens  tokens consumed inside the window
 * @return void
 */
function recordCompletedWindow(Account $account, Carbon $resetAt, int $peakUtil, int $tokens): void
{
    // Two snapshots: one early/low, one at the peak -- the estimator must
    // take the peak, not the latest or the first.
    AccountUsageSnapshot::factory()->for($account)->create([
        'util_7d' => (int) round($peakUtil / 2),
        'reset_7d_at' => $resetAt,
        'created_at' => $resetAt->copy()->subDays(5),
    ]);
    AccountUsageSnapshot::factory()->for($account)->create([
        'util_7d' => $peakUtil,
        'reset_7d_at' => $resetAt,
        'created_at' => $resetAt->copy()->subHours(2),
    ]);

    Event::factory()->for($account)->for(User::factory())->create([
        'tokens' => $tokens,
        'created_at' => $resetAt->copy()->subDays(3),
    ]);
}

it('derives weekly capacity from a completed window: tokens consumed scaled up by the peak utilization', function () {
    Carbon::setTestNow('2026-09-12 00:00:00');
    $account = Account::factory()->connected()->create();

    // Window closed 2 days ago having reached 50% on 500,000 tokens, so a
    // full 100% would have taken 1,000,000.
    recordCompletedWindow($account, now()->subDays(2), peakUtil: 50, tokens: 500_000);

    expect(app(AccountCapacityEstimator::class)->weeklyCapacityTokens($account, RebalanceWindow::days(30)))
        ->toBe(1_000_000.0);

    Carbon::setTestNow();
});

it('ignores a window whose peak utilization is too low to scale up safely', function () {
    Carbon::setTestNow('2026-09-12 00:00:00');
    $account = Account::factory()->connected()->create();

    // 4% peak: a 1-point probe error would swing the extrapolation by a
    // quarter, so this window carries no usable signal at all.
    recordCompletedWindow($account, now()->subDays(2), peakUtil: 4, tokens: 40_000);

    expect(app(AccountCapacityEstimator::class)->weeklyCapacityTokens($account, RebalanceWindow::days(30)))
        ->toBeNull();

    Carbon::setTestNow();
});

it('takes the median across completed windows so one freak week cannot set the capacity', function () {
    Carbon::setTestNow('2026-09-12 00:00:00');
    $account = Account::factory()->connected()->create();

    // The freak week is freak because it barely touched the quota, not
    // because it carried a mountain: a week that really did put 4.5M through
    // at half utilisation would prove the account is worth at least 4.5M, and
    // no median may read it lower than a week of its own ledger.
    recordCompletedWindow($account, now()->subDays(2), peakUtil: 25, tokens: 1_000_000);  // 4,000,000
    recordCompletedWindow($account, now()->subDays(9), peakUtil: 50, tokens: 1_000_000);  // 2,000,000
    recordCompletedWindow($account, now()->subDays(16), peakUtil: 80, tokens: 1_600_000); // 2,000,000

    expect(app(AccountCapacityEstimator::class)->weeklyCapacityTokens($account, RebalanceWindow::days(30)))
        ->toBe(2_000_000.0);

    Carbon::setTestNow();
});

it('ignores the still-open window, which has only partial usage recorded against it', function () {
    Carbon::setTestNow('2026-09-12 00:00:00');
    $account = Account::factory()->connected()->create();

    // Open window: resets in 5 days, already at 60% -- but only the usage so
    // far is on record, so scaling it up would badly understate capacity.
    AccountUsageSnapshot::factory()->for($account)->create([
        'util_7d' => 60, 'reset_7d_at' => now()->addDays(5), 'created_at' => now(),
    ]);
    Event::factory()->for($account)->for(User::factory())->create([
        'tokens' => 600_000, 'created_at' => now()->subDay(),
    ]);

    expect(app(AccountCapacityEstimator::class)->weeklyCapacityTokens($account, RebalanceWindow::days(30)))
        ->toBeNull();

    Carbon::setTestNow();
});

it('merges snapshots whose reset time differs only by probe jitter into one window', function () {
    Carbon::setTestNow('2026-09-12 00:00:00');
    $account = Account::factory()->connected()->create();
    $resetAt = now()->subDays(2);

    // The prober records the same reset boundary a second either side of the
    // hour; treating those as two windows would halve the usage attributed
    // to each and read as two unrelated (and wrong) capacities.
    AccountUsageSnapshot::factory()->for($account)->create([
        'util_7d' => 20, 'reset_7d_at' => $resetAt->copy()->subSecond(), 'created_at' => $resetAt->copy()->subDays(4),
    ]);
    AccountUsageSnapshot::factory()->for($account)->create([
        'util_7d' => 50, 'reset_7d_at' => $resetAt, 'created_at' => $resetAt->copy()->subHours(2),
    ]);
    Event::factory()->for($account)->for(User::factory())->create([
        'tokens' => 500_000, 'created_at' => $resetAt->copy()->subDays(3),
    ]);

    expect(app(AccountCapacityEstimator::class)->weeklyCapacityTokens($account, RebalanceWindow::days(30)))
        ->toBe(1_000_000.0);

    Carbon::setTestNow();
});

it('falls back to the median capacity of same-plan accounts when one has no usable history', function () {
    Carbon::setTestNow('2026-09-12 00:00:00');

    $measured = Account::factory()->connected()->create(['plan' => AccountPlan::Max20x]);
    recordCompletedWindow($measured, now()->subDays(2), peakUtil: 50, tokens: 500_000); // 1,000,000

    $newcomer = Account::factory()->connected()->create(['plan' => AccountPlan::Max20x]);

    $capacities = app(AccountCapacityEstimator::class)
        ->capacitiesFor(collect([$measured, $newcomer]), RebalanceWindow::days(30));

    expect($capacities[$measured->id]['tokens'])->toBe(1_000_000.0)
        ->and($capacities[$newcomer->id]['tokens'])->toBe(1_000_000.0)
        ->and($capacities[$newcomer->id]['basis'])->toBe('inherited');

    Carbon::setTestNow();
});

it('leaves capacity unknown when nothing in the fleet has usable history', function () {
    Carbon::setTestNow('2026-09-12 00:00:00');

    $a = Account::factory()->connected()->create();
    $b = Account::factory()->connected()->create();

    $capacities = app(AccountCapacityEstimator::class)
        ->capacitiesFor(collect([$a, $b]), RebalanceWindow::days(30));

    expect($capacities[$a->id]['tokens'])->toBeNull()
        ->and($capacities[$b->id]['tokens'])->toBeNull()
        ->and($capacities[$a->id]['basis'])->toBe('unknown');

    Carbon::setTestNow();
});

it('takes a week the account actually ran out of as the measurement it is', function () {
    Carbon::setTestNow('2026-09-12 00:00:00');
    $account = Account::factory()->connected()->create();

    // A week that hit the ceiling measures capacity directly: people were cut
    // off, so what they spent IS the week's worth. A quiet week multiplies a
    // quarter of a week by four and multiplies the probe's error by four with
    // it. Treating the two as equal evidence let the quiet one pull this
    // account's capacity 26% above what the busy one plainly showed.
    recordCompletedWindow($account, now()->subDays(2), peakUtil: 100, tokens: 35_000_000);
    recordCompletedWindow($account, now()->subDays(9), peakUtil: 25, tokens: 15_000_000);

    expect(app(AccountCapacityEstimator::class)->weeklyCapacityTokens($account, RebalanceWindow::days(30)))
        ->toBe(35_000_000.0);

    Carbon::setTestNow();
});

it('takes the median of the weeks it ran out of, not the most recent one', function () {
    Carbon::setTestNow('2026-09-12 00:00:00');
    $account = Account::factory()->connected()->create();

    // How many tokens a week's quota is worth genuinely moves with the model
    // mix, so one saturated week is a point, not the answer.
    recordCompletedWindow($account, now()->subDays(2), peakUtil: 100, tokens: 28_000_000);
    recordCompletedWindow($account, now()->subDays(9), peakUtil: 100, tokens: 38_000_000);
    recordCompletedWindow($account, now()->subDays(16), peakUtil: 100, tokens: 24_000_000);

    expect(app(AccountCapacityEstimator::class)->weeklyCapacityTokens($account, RebalanceWindow::days(30)))
        ->toBe(28_000_000.0);

    Carbon::setTestNow();
});

it('still extrapolates when the account has never run out', function () {
    Carbon::setTestNow('2026-09-12 00:00:00');
    $account = Account::factory()->connected()->create();

    recordCompletedWindow($account, now()->subDays(2), peakUtil: 50, tokens: 20_000_000);
    recordCompletedWindow($account, now()->subDays(9), peakUtil: 40, tokens: 16_000_000);

    expect(app(AccountCapacityEstimator::class)->weeklyCapacityTokens($account, RebalanceWindow::days(30)))
        ->toBe(40_000_000.0);

    Carbon::setTestNow();
});

it('says whether a capacity was measured or merely extrapolated', function () {
    Carbon::setTestNow('2026-09-12 00:00:00');
    $measured = Account::factory()->connected()->create();
    $guessed = Account::factory()->connected()->create();
    $silent = Account::factory()->connected()->create();

    recordCompletedWindow($measured, now()->subDays(2), peakUtil: 100, tokens: 30_000_000);
    recordCompletedWindow($guessed, now()->subDays(2), peakUtil: 40, tokens: 12_000_000);

    $resolved = app(AccountCapacityEstimator::class)->capacitiesFor(collect([$measured, $guessed, $silent]), RebalanceWindow::days(30));

    // An admin deciding what to buy needs to know which of these numbers is
    // a reading and which is an inference.
    expect($resolved[$measured->id]['basis'])->toBe('measured')
        ->and($resolved[$guessed->id]['basis'])->toBe('extrapolated')
        ->and($resolved[$silent->id]['basis'])->toBe('inherited');

    Carbon::setTestNow();
});

it('merges a reset recorded a second before the hour with the one recorded on it', function () {
    Carbon::setTestNow('2026-09-12 00:00:00');
    $account = Account::factory()->connected()->create();
    $resetAt = Carbon::parse('2026-09-10 06:00:00');

    // Prod records the same boundary as both 05:59:59 and 06:00:00. Flooring
    // to the hour drops those into different buckets, so one week is measured
    // twice -- once missing whatever landed in its final hour -- and the card
    // claims two windows of evidence where the account has one.
    foreach ([$resetAt->copy()->subSecond(), $resetAt] as $recorded) {
        AccountUsageSnapshot::factory()->for($account)->create([
            'util_7d' => 100,
            'reset_7d_at' => $recorded,
            'created_at' => $resetAt->copy()->subDay(),
        ]);
    }

    Event::factory()->for($account)->for(User::factory())->create([
        'tokens' => 10_000_000,
        'created_at' => $resetAt->copy()->subDays(3),
    ]);
    Event::factory()->for($account)->for(User::factory())->create([
        'tokens' => 2_000_000,
        'created_at' => $resetAt->copy()->subMinutes(30),
    ]);

    $measured = app(AccountCapacityEstimator::class)->measure($account, RebalanceWindow::days(30));

    expect($measured['windows'])->toBe(1)
        ->and($measured['tokens'])->toBe(12_000_000.0);

    Carbon::setTestNow();
});

it('does not let one exhausted week overturn several consistent quieter ones', function () {
    Carbon::setTestNow('2026-09-12 00:00:00');
    $account = Account::factory()->connected()->create();

    // Measured on prod: three weeks agreeing the account is worth ~27M, then
    // one week that ran out on 6M because that week's work was cache-heavy --
    // a quota buys far fewer recorded tokens some weeks than others. Trusting
    // the exhausted week alone read the account at a quarter of its size and
    // reported it 581% full.
    recordCompletedWindow($account, now()->subDays(2), peakUtil: 100, tokens: 6_000_000);
    recordCompletedWindow($account, now()->subDays(9), peakUtil: 50, tokens: 13_000_000);
    recordCompletedWindow($account, now()->subDays(16), peakUtil: 50, tokens: 14_000_000);
    recordCompletedWindow($account, now()->subDays(23), peakUtil: 50, tokens: 15_000_000);

    $measured = app(AccountCapacityEstimator::class)->measure($account, RebalanceWindow::days(30));

    expect($measured['tokens'])->toBe(27_000_000.0)
        ->and($measured['basis'])->toBe('contested');

    Carbon::setTestNow();
});

it('never reads an account as smaller than a week it demonstrably carried', function () {
    Carbon::setTestNow('2026-09-12 00:00:00');
    $account = Account::factory()->connected()->create();

    // A week that ran out on 20M, and a week that carried 30M without even
    // filling. The second one does not need extrapolating to contradict the
    // first: 30M went through this account inside one of its own quota
    // weeks, so a week of its quota cannot be worth 20M. On prod this had
    // oedevai4 published at 28.3M while a 77% week had carried 40.2M, and
    // the page then showed it "peaking at 142% of capacity".
    recordCompletedWindow($account, now()->subDays(2), peakUtil: 100, tokens: 20_000_000);
    recordCompletedWindow($account, now()->subDays(9), peakUtil: 75, tokens: 30_000_000);

    $measured = app(AccountCapacityEstimator::class)->measure($account, RebalanceWindow::days(30));

    expect($measured['tokens'])->toBe(30_000_000.0)
        ->and($measured['basis'])->toBe('contested');

    Carbon::setTestNow();
});
