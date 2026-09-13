<?php

use App\Models\Account;
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
