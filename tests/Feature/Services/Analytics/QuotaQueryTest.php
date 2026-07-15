<?php

use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use App\Services\Analytics\AccountQuotaHistoryQuery;
use App\Services\Analytics\QuotaGaugesQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it builds a gauge row per account with a near-cap flag and projections', function () {
    $hot = Account::factory()->create(['email' => 'hot@example.com']);
    AccountUsageSnapshot::factory()->for($hot)->create([
        'util_5h' => 40,
        'util_7d' => 90,
        'reset_5h_at' => now()->addHours(1),
        'reset_7d_at' => now()->addDay(),
        'created_at' => now(),
    ]);

    $cool = Account::factory()->create(['email' => 'cool@example.com']);
    AccountUsageSnapshot::factory()->for($cool)->create([
        'util_7d' => 10,
        'reset_7d_at' => now()->addDays(3),
        'created_at' => now(),
    ]);

    $rows = collect(app(QuotaGaugesQuery::class)->get())->keyBy('email');

    expect($rows['hot@example.com']['near_cap'])->toBeTrue()
        ->and($rows['hot@example.com']['util_7d'])->toBe(90)
        ->and($rows['hot@example.com']['projected_7d'])->toBeGreaterThanOrEqual(90)
        ->and($rows['cool@example.com']['near_cap'])->toBeFalse();
});

test('an account with no snapshot reports null utilization and no near-cap', function () {
    Account::factory()->create(['email' => 'fresh@example.com']);

    $row = collect(app(QuotaGaugesQuery::class)->get())->firstWhere('email', 'fresh@example.com');

    expect($row['util_7d'])->toBeNull()
        ->and($row['projected_7d'])->toBeNull()
        ->and($row['near_cap'])->toBeFalse();
});

test('it returns one account quota-history row per snapshot in range, ordered ascending', function () {
    $account = Account::factory()->create();
    AccountUsageSnapshot::factory()->for($account)->create(['util_7d' => 10, 'created_at' => now()->subHours(2)]);
    AccountUsageSnapshot::factory()->for($account)->create(['util_7d' => 20, 'created_at' => now()->subHour()]);
    AccountUsageSnapshot::factory()->for($account)->create(['util_7d' => 99, 'created_at' => now()->subDays(40)]); // out of range

    $rows = app(AccountQuotaHistoryQuery::class)->get($account, now()->subDay(), now());

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['util_7d'])->toBe(10)
        ->and($rows[1]['util_7d'])->toBe(20);
});
