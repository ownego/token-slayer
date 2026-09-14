<?php

use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use App\Models\Event;
use App\Models\User;
use App\Services\Accounts\ObservedFleetLoad;
use App\Services\Accounts\RebalanceWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * Seven consecutive days of identical usage on one account, starting the day
 * after `$from`.
 *
 * @param  Account  $account  the account the usage is attributed to
 * @param  string  $from  the day before the run starts
 * @param  int  $tokensPerDay  tokens on each of the seven days
 * @return void
 */
function weekOfUsage(Account $account, string $from, int $tokensPerDay): void
{
    $user = User::factory()->create();

    foreach (range(1, 7) as $offset) {
        Event::factory()->for($account)->for($user)->create([
            'tokens' => $tokensPerDay,
            'created_at' => Carbon::parse($from)->addDays($offset),
        ]);
    }
}

it('reads the heaviest week each account actually carried', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    $account = Account::factory()->connected()->create();
    weekOfUsage($account, '2026-09-04 12:00:00', 100_000);

    $observed = app(ObservedFleetLoad::class)->measure(
        collect([$account]),
        [$account->id => 1_400_000.0],
        RebalanceWindow::days(30),
    );

    expect($observed['per_account'][$account->id]['peak_tokens'])->toBe(700_000.0)
        ->and(round($observed['per_account'][$account->id]['peak_percent']))->toBe(50.0);

    Carbon::setTestNow();
});

it('counts the accounts that actually blew past their weekly capacity', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    $over = Account::factory()->connected()->create();
    $under = Account::factory()->connected()->create();

    weekOfUsage($over, '2026-09-04 12:00:00', 100_000);
    weekOfUsage($under, '2026-09-04 12:00:00', 100_000);

    $observed = app(ObservedFleetLoad::class)->measure(
        collect([$over, $under]),
        [$over->id => 500_000.0, $under->id => 1_000_000.0],
        RebalanceWindow::days(30),
    );

    // This is the figure a team can check against their own memory of last
    // week, which is what makes the rest of the page believable.
    expect($observed['accounts_over_capacity'])->toBe(1)
        ->and($observed['accounts_measured'])->toBe(2);

    Carbon::setTestNow();
});

it('measures the fleet\'s own heaviest week, never the sum of each account\'s', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    $first = Account::factory()->connected()->create();
    $second = Account::factory()->connected()->create();

    // Their heavy weeks are a fortnight apart.
    weekOfUsage($first, '2026-09-04 12:00:00', 100_000);
    weekOfUsage($second, '2026-08-21 12:00:00', 100_000);

    $observed = app(ObservedFleetLoad::class)->measure(
        collect([$first, $second]),
        [$first->id => 1_000_000.0, $second->id => 1_000_000.0],
        RebalanceWindow::days(30),
    );

    // Adding the two peaks would claim the fleet once needed 1,400,000 in a
    // week. It never did: the most it ever got through was 700,000. The sum
    // of maxima is not the maximum of the sum, and treating it as one is
    // what makes a fleet look short of capacity it already has.
    expect($observed['fleet_peak_tokens'])->toBe(700_000.0)
        ->and(round($observed['fleet_peak_percent']))->toBe(35.0);

    Carbon::setTestNow();
});

it('reports nothing rather than dividing by a capacity it does not have', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    $account = Account::factory()->connected()->create();
    weekOfUsage($account, '2026-09-04 12:00:00', 100_000);

    $observed = app(ObservedFleetLoad::class)->measure(collect([$account]), [], RebalanceWindow::days(30));

    expect($observed['accounts_measured'])->toBe(0)
        ->and($observed['fleet_peak_percent'])->toBe(0.0);

    Carbon::setTestNow();
});

it('measures an account against its own quota weeks, not seven days that straddle two', function () {
    // A rolling seven days can take the tail of one quota week and the head
    // of the next, so it can hold nearly two weeks of allowance and exceed
    // 100% without the account ever having run short. On prod this reported
    // oeqaai1 at 164% and oedevai4 at 147%, and those figures were being
    // shown as the proof that accounts were blowing past their capacity.
    Carbon::setTestNow('2026-09-14 06:00:00');
    $account = Account::factory()->connected()->create();
    $user = User::factory()->create();

    foreach (['2026-09-06 00:00:00', '2026-09-13 00:00:00'] as $resetAt) {
        AccountUsageSnapshot::factory()->for($account)->create([
            'util_7d' => 60,
            'reset_7d_at' => Carbon::parse($resetAt),
            'created_at' => Carbon::parse($resetAt)->subDay(),
        ]);
    }

    // Three heavy days closing one week, four opening the next: seven
    // consecutive days of 6M, split 18M / 24M across the boundary.
    foreach (['2026-09-03', '2026-09-04', '2026-09-05', '2026-09-06', '2026-09-07', '2026-09-08', '2026-09-09'] as $day) {
        Event::factory()->for($account)->for($user)->create([
            'tokens' => 6_000_000,
            'created_at' => Carbon::parse("{$day} 12:00:00"),
        ]);
    }

    $observed = app(ObservedFleetLoad::class)->measure(
        collect([$account]),
        [$account->id => 30_000_000.0],
        RebalanceWindow::days(30),
    );

    expect($observed['per_account'][$account->id]['peak_tokens'])->toBe(24_000_000.0)
        ->and($observed['per_account'][$account->id]['peak_percent'])->toBe(80.0)
        ->and($observed['accounts_over_capacity'])->toBe(0);

    Carbon::setTestNow();
});
