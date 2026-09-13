<?php

use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use App\Models\Event;
use App\Models\User;
use App\Services\Accounts\AccountRebalanceRecommender;
use App\Services\Accounts\RebalanceWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * Seven equal daily events for one person on one account, landing inside the
 * closed quota window every fixture here measures capacity from.
 *
 * @param  Account  $account  the account the usage is attributed to
 * @param  User  $user  the person who spent it
 * @param  int  $tokensPerDay  tokens on each of the seven days
 * @return void
 */
function dailyUsage(Account $account, User $user, int $tokensPerDay): void
{
    foreach (range(1, 7) as $offset) {
        Event::factory()->for($account)->for($user)->create([
            'tokens' => $tokensPerDay,
            'created_at' => Carbon::parse('2026-09-04 12:00:00')->addDays($offset),
        ]);
    }
}

/**
 * A single ancient token, far outside the analysis window: it moves nothing
 * in any demand or capacity figure but gives the account and the person
 * enough recorded history to clear `min_history_days`.
 *
 * @param  Account  $account  the account to age
 * @param  User  $user  the person to age
 * @return void
 */
function ancientUsage(Account $account, User $user): void
{
    Event::factory()->for($account)->for($user)->create([
        'tokens' => 1,
        'created_at' => Carbon::parse('2026-08-23 12:00:00'),
    ]);
}

/**
 * An account whose closed 7-day window peaked at `$peakUtil`, which is what
 * turns its recorded tokens into a capacity figure.
 *
 * @param  int  $peakUtil  the highest util_7d the closed window reached
 * @return Account
 */
function accountPeaking(int $peakUtil): Account
{
    $account = Account::factory()->connected()->create();

    AccountUsageSnapshot::factory()->for($account)->create([
        'util_7d' => $peakUtil,
        'reset_7d_at' => Carbon::parse('2026-09-12 00:00:00'),
        'created_at' => Carbon::parse('2026-09-11 23:00:00'),
    ]);

    return $account;
}

/**
 * Put a person on an account as a tracked member.
 *
 * @param  Account  $account  the account to join
 * @param  User  $user  the person joining
 * @return void
 */
function tracks(Account $account, User $user): void
{
    $account->users()->syncWithoutDetaching([$user->id => ['status' => MembershipStatus::Tracked->value]]);
}

it('recommends a two-way swap, moving a whale off the tight account and a lighter member back onto it', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');

    // tight: 1,050,000 tokens took it to 70% -> capacity 1,500,000/week.
    // roomy: the same 1,050,000 tokens only reached 35% -> capacity
    // 3,000,000/week. The whale sits on the tight one and the three lighter
    // members on the roomy one, so the only way to level them is to send the
    // whale across AND bring someone back the other way.
    $tight = accountPeaking(70);
    $roomy = accountPeaking(35);

    $whale = User::factory()->create();
    $mediumA = User::factory()->create();
    $mediumB = User::factory()->create();
    $mouse = User::factory()->create();

    dailyUsage($tight, $whale, 150_000);
    dailyUsage($roomy, $mediumA, 70_000);
    dailyUsage($roomy, $mediumB, 70_000);
    dailyUsage($roomy, $mouse, 10_000);

    foreach ([$whale, $mediumA, $mediumB, $mouse] as $member) {
        ancientUsage($member === $whale ? $tight : $roomy, $member);
    }

    tracks($tight, $whale);
    tracks($roomy, $mediumA);
    tracks($roomy, $mediumB);
    tracks($roomy, $mouse);

    $result = app(AccountRebalanceRecommender::class)->recommend(RebalanceWindow::days(14));

    $directions = collect($result['moves'])
        ->map(fn ($move): string => "{$move->fromAccountId}->{$move->toAccountId}")
        ->unique()->sort()->values()->all();

    expect($directions)->toBe([
        "{$tight->id}->{$roomy->id}",
        "{$roomy->id}->{$tight->id}",
    ], 'a rebalance must send people in both directions, not pile everyone onto the roomiest account');

    // The whale is the one who leaves the tight account, and the move it is
    // paired with is the person coming back to fill the space.
    $outbound = collect($result['moves'])->firstWhere('fromAccountId', $tight->id);
    expect($outbound->userId)->toBe($whale->id)
        ->and($outbound->swapWithUserId)->not->toBeNull();

    // The fullest account in the fleet is measurably better off afterwards.
    expect(round($result['peak_fill_before_percent']))->toBe(70.0)
        ->and($result['peak_fill_after_percent'])->toBeLessThan(60.0);

    Carbon::setTestNow();
});

it('recommends nothing when shuffling people would not relieve the fullest account', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');

    // Two accounts of identical capacity, each already carrying the same
    // weekly load. A bin-packer will happily repack them -- one whale here,
    // two mediums there -- but the fullest account ends up at exactly the
    // same percentage, so every one of those moves is pure churn.
    $accountA = accountPeaking(50);
    $accountB = accountPeaking(50);

    $whale = User::factory()->create();
    $mediumA = User::factory()->create();
    $mediumB = User::factory()->create();

    dailyUsage($accountB, $whale, 150_000);
    dailyUsage($accountA, $mediumA, 75_000);
    dailyUsage($accountA, $mediumB, 75_000);

    ancientUsage($accountB, $whale);
    ancientUsage($accountA, $mediumA);
    ancientUsage($accountA, $mediumB);

    tracks($accountB, $whale);
    tracks($accountA, $mediumA);
    tracks($accountA, $mediumB);

    $result = app(AccountRebalanceRecommender::class)->recommend(RebalanceWindow::days(14));

    expect($result['moves'])->toBe([])
        ->and($result['peak_fill_after_percent'])->toBe($result['peak_fill_before_percent']);

    Carbon::setTestNow();
});

it('carries the figures behind a move so an admin can see why it was proposed', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');

    $tight = accountPeaking(70);
    $roomy = accountPeaking(35);

    $whale = User::factory()->create();
    $mediumA = User::factory()->create();
    $mediumB = User::factory()->create();
    $mouse = User::factory()->create();

    dailyUsage($tight, $whale, 150_000);
    dailyUsage($roomy, $mediumA, 70_000);
    dailyUsage($roomy, $mediumB, 70_000);
    dailyUsage($roomy, $mouse, 10_000);

    ancientUsage($tight, $whale);
    ancientUsage($roomy, $mediumA);
    ancientUsage($roomy, $mediumB);
    ancientUsage($roomy, $mouse);

    tracks($tight, $whale);
    tracks($roomy, $mediumA);
    tracks($roomy, $mediumB);
    tracks($roomy, $mouse);

    $result = app(AccountRebalanceRecommender::class)->recommend(RebalanceWindow::days(14));
    $move = collect($result['moves'])->firstWhere('userId', $whale->id);

    // 150,000/day for seven days, read off the peak run rather than the
    // 14-day trailing average (which has been halved by the quiet week).
    expect($move->demandPerDayTokens)->toBe(150_000.0)
        ->and($move->demandWeeklyTokens)->toBe(1_050_000.0)
        ->and($move->demandBasis)->toBe('peak_rate')
        ->and($move->trailingAvgPerDayTokens)->toBe(75_000.0)
        ->and($move->peakAvgPerDayTokens)->toBe(150_000.0)
        ->and($move->burstFactor)->toBe(1.0)
        ->and($move->quotaWeight)->toBe(1.0)
        ->and($move->daysOfHistory)->toBe(6)
        ->and($move->confident)->toBeTrue();

    // Both sides of the move show what it does to each account.
    expect(round($move->fromFillBeforePercent))->toBe(70.0)
        ->and($move->fromFillAfterPercent)->toBeLessThan($move->fromFillBeforePercent)
        ->and($move->toFillAfterPercent)->toBeGreaterThan($move->toFillBeforePercent);

    // And the per-account summary the page renders alongside the table.
    expect($result['accounts'][$tight->id]['capacity_tokens'])->toBe(1_500_000.0)
        ->and($result['accounts'][$roomy->id]['capacity_tokens'])->toBe(3_000_000.0)
        ->and($result['window_label'])->toBe('last 14 days');

    Carbon::setTestNow();
});

it('marks a move as unconfident when the accounts have barely any history behind them', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');

    // Same shape as the swap fixture, minus the ancient events: everything
    // on record is under a week old, which is below min_history_days.
    $tight = accountPeaking(70);
    $roomy = accountPeaking(35);

    $whale = User::factory()->create();
    $mediumA = User::factory()->create();
    $mediumB = User::factory()->create();
    $mouse = User::factory()->create();

    dailyUsage($tight, $whale, 150_000);
    dailyUsage($roomy, $mediumA, 70_000);
    dailyUsage($roomy, $mediumB, 70_000);
    dailyUsage($roomy, $mouse, 10_000);

    tracks($tight, $whale);
    tracks($roomy, $mediumA);
    tracks($roomy, $mediumB);
    tracks($roomy, $mouse);

    $result = app(AccountRebalanceRecommender::class)->recommend(RebalanceWindow::days(14));

    expect($result['moves'])->not->toBeEmpty()
        ->and(collect($result['moves'])->every(fn ($move): bool => $move->confident === false))->toBeTrue();

    Carbon::setTestNow();
});

it('reports the tokens that fit nowhere instead of quietly overfilling an account', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');

    // One account, one person wanting far more than a week of it.
    $account = accountPeaking(90);
    $whale = User::factory()->create();
    dailyUsage($account, $whale, 150_000);
    ancientUsage($account, $whale);
    tracks($account, $whale);

    $result = app(AccountRebalanceRecommender::class)->recommend(RebalanceWindow::days(14));

    // Capacity 1,166,666; usable after the 20% safety margin 933,333; the
    // whale wants 1,050,000 a week.
    expect($result['unplaced_tokens'])->toBeGreaterThan(0.0)
        ->and($result['moves'])->toBe([]);

    Carbon::setTestNow();
});
