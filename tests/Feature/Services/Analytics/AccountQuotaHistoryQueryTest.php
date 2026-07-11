<?php

use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use App\Services\Analytics\AccountQuotaHistoryQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it aggregates snapshots into one point per hour using the last reading in each hour', function () {
    $account = Account::factory()->create();
    $tenAm = now()->startOfDay()->setHour(10);

    // Two snapshots inside the 10:00 hour, one inside 11:00.
    AccountUsageSnapshot::factory()->for($account)->create(['util_5h' => 10, 'util_7d' => 20, 'created_at' => $tenAm->copy()->setMinute(5)]);
    AccountUsageSnapshot::factory()->for($account)->create(['util_5h' => 30, 'util_7d' => 25, 'created_at' => $tenAm->copy()->setMinute(40)]);
    AccountUsageSnapshot::factory()->for($account)->create(['util_5h' => 50, 'util_7d' => 35, 'created_at' => $tenAm->copy()->addHour()->setMinute(15)]);

    $rows = app(AccountQuotaHistoryQuery::class)->get($account, $tenAm->copy()->subHour(), $tenAm->copy()->addHours(2));

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['bucket'])->toBe($tenAm->copy()->format('Y-m-d H:00'))
        ->and($rows[0]['util_5h'])->toBe(30)
        ->and($rows[0]['util_7d'])->toBe(25)
        ->and($rows[1]['bucket'])->toBe($tenAm->copy()->addHour()->format('Y-m-d H:00'))
        ->and($rows[1]['util_5h'])->toBe(50)
        ->and($rows[1]['util_7d'])->toBe(35);
});
