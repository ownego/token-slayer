<?php

use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use App\Services\Accounts\ClosedQuotaWindows;
use App\Services\Accounts\RebalanceWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('treats a boundary reported either side of the hour as one window', function () {
    // The API reports the same reset as 05:59:59, 06:00:00 and 06:00:01 over
    // a week of probing. Anything that floors to the hour splits the first
    // away from the other two -- which is how two separate services came to
    // count every week of every account twice.
    Carbon::setTestNow('2026-09-14 12:00:00');
    $account = Account::factory()->connected()->create();

    foreach (['05:59:59' => 40, '06:00:00' => 90, '06:00:01' => 70] as $time => $util) {
        AccountUsageSnapshot::factory()->for($account)->create([
            'util_7d' => $util,
            'reset_7d_at' => Carbon::parse("2026-09-12 {$time}"),
            'created_at' => Carbon::parse('2026-09-11 00:00:00'),
        ]);
    }

    $windows = app(ClosedQuotaWindows::class)->peaks($account, RebalanceWindow::days(30));

    expect($windows)->toHaveCount(1)
        ->and($windows[0]['peak_util'])->toBe(90)
        ->and($windows[0]['reset_at']->toDateTimeString())->toBe('2026-09-12 06:00:00')
        ->and($windows[0]['opened_at']->toDateTimeString())->toBe('2026-09-05 06:00:00');

    Carbon::setTestNow();
});

it('leaves out the window that has not closed yet', function () {
    // Only part of an open window's usage is on record, so any figure read
    // off it describes a week that is still happening.
    Carbon::setTestNow('2026-09-14 12:00:00');
    $account = Account::factory()->connected()->create();

    AccountUsageSnapshot::factory()->for($account)->create([
        'util_7d' => 60,
        'reset_7d_at' => Carbon::parse('2026-09-19 06:00:00'),
        'created_at' => now(),
    ]);

    expect(app(ClosedQuotaWindows::class)->peaks($account, RebalanceWindow::days(30)))->toBe([]);

    Carbon::setTestNow();
});

it('hands back each window its whole curve, in the order it was read', function () {
    // The suppressed-demand estimator needs the shape of the climb, not just
    // where it ended -- it reads the rate off the part before the ceiling.
    Carbon::setTestNow('2026-09-14 12:00:00');
    $account = Account::factory()->connected()->create();

    foreach ([['2026-09-07 06:00:00', 20], ['2026-09-09 06:00:00', 55], ['2026-09-11 06:00:00', 95]] as [$at, $util]) {
        AccountUsageSnapshot::factory()->for($account)->create([
            'util_7d' => $util,
            'reset_7d_at' => Carbon::parse('2026-09-12 05:59:59'),
            'created_at' => Carbon::parse($at),
        ]);
    }

    $curves = app(ClosedQuotaWindows::class)->curves($account, RebalanceWindow::days(30));

    expect($curves)->toHaveCount(1)
        ->and(array_column($curves[0], 'util'))->toBe([20, 55, 95])
        ->and($curves[0][0]['opened_at']->toDateTimeString())->toBe('2026-09-05 06:00:00');

    Carbon::setTestNow();
});
