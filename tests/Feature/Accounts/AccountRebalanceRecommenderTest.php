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

    // 1,050,000 in their heaviest week, which is the figure a weekly quota
    // can be compared against -- not the 14-day trailing average, halved by
    // the quiet week on either side of it.
    expect($move->demandPerDayTokens)->toBe(150_000.0)
        ->and($move->demandWeeklyTokens)->toBe(1_050_000.0)
        ->and($move->demandBasis)->toBe('peak_week')
        ->and($move->trailingAvgPerDayTokens)->toBe(75_000.0)
        ->and($move->peakWeekTokens)->toBe(1_050_000.0)
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

    // Two people on one account whose heavy weeks fell in DIFFERENT weeks.
    // That is the only way a fleet can be genuinely too small: capacity is
    // measured from a week that actually happened, so a single week's usage
    // can never exceed it. Planning has to assume both could go heavy at
    // once, and here that is nearly twice what the account can serve.
    $account = accountPeaking(90);
    $whale = User::factory()->create();
    $straggler = User::factory()->create();

    dailyUsage($account, $whale, 150_000);
    foreach (range(1, 7) as $offset) {
        Event::factory()->for($account)->for($straggler)->create([
            'tokens' => 150_000,
            'created_at' => Carbon::parse('2026-08-21 12:00:00')->addDays($offset),
        ]);
    }

    ancientUsage($account, $whale);
    tracks($account, $whale);
    tracks($account, $straggler);

    $result = app(AccountRebalanceRecommender::class)->recommend(RebalanceWindow::days(30));

    // Capacity 1,166,666/week, measured from the week only the whale was
    // active; the pair want 2,100,000 between them.
    expect($result['unplaced_tokens'])->toBeGreaterThan(0.0)
        // Nowhere to move anyone to — a second account is the only fix.
        ->and($result['moves'])->toBe([]);

    Carbon::setTestNow();
});

it('plans people onto an account that has not been bought yet, and marks it as such', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');

    $tight = accountPeaking(70);
    $roomy = accountPeaking(35);
    $whale = User::factory()->create();
    $mediumA = User::factory()->create();
    $mediumB = User::factory()->create();

    dailyUsage($tight, $whale, 150_000);
    dailyUsage($roomy, $mediumA, 70_000);
    dailyUsage($roomy, $mediumB, 70_000);
    foreach ([[$tight, $whale], [$roomy, $mediumA], [$roomy, $mediumB]] as [$account, $user]) {
        ancientUsage($account, $user);
        tracks($account, $user);
    }

    $result = app(AccountRebalanceRecommender::class)->recommend(RebalanceWindow::days(14), extraAccounts: 1);

    // Asking "what if we bought one" has to change the plan itself, not just
    // produce a side panel: an arrangement that ignores an account about to
    // exist is an arrangement for the wrong fleet.
    $onto = collect($result['moves'])->filter(fn ($move): bool => $move->toAccountId < 0);
    expect($onto)->not->toBeEmpty()
        ->and($result['simulated_accounts'])->toBe(1);

    $new = collect($result['accounts'])->firstWhere('is_new', true);
    expect($new['email'])->toBe('New account 1')
        ->and($new['members_before'])->toBe(0)
        ->and($new['members_after'])->toBeGreaterThan(0)
        ->and($result['capacity_tokens'])->toBeGreaterThan(array_sum(collect($result['accounts'])->where('is_new', false)->pluck('capacity_tokens')->all()));

    Carbon::setTestNow();
});

it('leaves the fleet as it is when no extra account is being simulated', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    $account = accountPeaking(70);
    $user = User::factory()->create();
    dailyUsage($account, $user, 150_000);
    ancientUsage($account, $user);
    tracks($account, $user);

    $result = app(AccountRebalanceRecommender::class)->recommend(RebalanceWindow::days(14));

    expect($result['simulated_accounts'])->toBe(0)
        ->and(collect($result['accounts'])->where('is_new', true))->toBeEmpty();

    Carbon::setTestNow();
});

it('counts a switched member on their new account the moment the seat moves', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');

    $from = accountPeaking(70);
    $to = accountPeaking(35);
    $mover = User::factory()->create();
    $other = User::factory()->create();

    // All of the mover's usage is on the account they are leaving — they
    // have not touched the new one yet, which is the state a Switch leaves
    // behind the instant it succeeds.
    dailyUsage($from, $mover, 150_000);
    dailyUsage($to, $other, 70_000);
    ancientUsage($from, $mover);
    ancientUsage($to, $other);
    tracks($to, $other);

    tracks($from, $mover);
    $stillOnFrom = app(AccountRebalanceRecommender::class)->recommend(RebalanceWindow::days(14));

    // Exactly what switchUserAction writes: tracked on the destination,
    // demoted on the source.
    tracks($to, $mover);
    $from->trackedUsers()->updateExistingPivot($mover->id, ['status' => MembershipStatus::Untracked->value]);

    $after = app(AccountRebalanceRecommender::class)->recommend(RebalanceWindow::days(14));

    $fill = fn (array $result, int $id): float => collect($result['accounts'])->firstWhere('id', $id)['fill_before_percent'];

    // Their whole appetite moves with them, even though every token of it
    // was spent on the account they left: demand follows the person, and the
    // seat says where that person now is.
    expect($fill($stillOnFrom, $from->id))->toBeGreaterThan(0.0)
        ->and($fill($after, $from->id))->toBe(0.0)
        ->and($fill($after, $to->id))->toBeGreaterThan($fill($stillOnFrom, $to->id));

    Carbon::setTestNow();
});

it('names what is short of history rather than leaving the doubt on the person', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');

    // An overloaded account of long standing, and a roomy one opened three
    // days ago.
    $established = accountPeaking(100);
    $brandNew = accountPeaking(35);

    foreach ([User::factory()->create(), User::factory()->create()] as $veteran) {
        dailyUsage($established, $veteran, 150_000);
        ancientUsage($established, $veteran);
        tracks($established, $veteran);
    }

    $newcomer = User::factory()->create();
    foreach (range(1, 3) as $offset) {
        Event::factory()->for($brandNew)->for($newcomer)->create([
            'tokens' => 300_000,
            'created_at' => Carbon::parse('2026-09-08 12:00:00')->addDays($offset),
        ]);
    }
    tracks($brandNew, $newcomer);

    $result = app(AccountRebalanceRecommender::class)->recommend(RebalanceWindow::days(14));
    $move = collect($result['moves'])->firstWhere('toAccountId', $brandNew->id);

    // Everything about the person moving is well established; the account
    // they are moving to is not. Saying only "not enough data" puts the doubt
    // on them, and an admin reads it beside their own long history and
    // concludes the page is wrong.
    expect($move)->not->toBeNull()
        ->and($move->daysOfHistory)->toBeGreaterThanOrEqual(6)
        ->and($move->confident)->toBeFalse()
        ->and($move->confidenceReason)->toContain($brandNew->email)
        ->and($move->confidenceReason)->toContain('under the 7 needed');

    Carbon::setTestNow();
});
