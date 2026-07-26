<?php

use App\Enums\AccountStatus;
use App\Models\Account;
use App\Services\Analytics\FleetUsageRefresher;
use App\Services\DamageTotals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('probes every probeable account and busts the damage-totals cache', function (): void {
    fakeAnthropic();
    Account::factory()->connected()->count(2)->create(['status' => AccountStatus::Active]);
    Cache::put(DamageTotals::CACHE_KEY, ['stale'], now()->addHour());

    $count = app(FleetUsageRefresher::class)->refresh();

    expect($count)->toBe(2)
        ->and(Cache::has(DamageTotals::CACHE_KEY))->toBeFalse();
    expect(Account::first()->usageSnapshots()->count())->toBeGreaterThan(0);
});

it('continues probing after a single account probe fails', function (): void {
    // UsageProber::probe() catches its own UsageProbeException internally for
    // a non-rate-limited failure (records probe_error, returns null) rather
    // than throwing, so this exercises the sweep continuing across accounts
    // rather than the refresher's own try/catch. Both accounts must still
    // show a recorded probe_error to prove neither was skipped.
    fakeAnthropic(['usage' => Http::response('', 500)]);
    Account::factory()->connected()->count(2)->create(['status' => AccountStatus::Active]);

    $count = app(FleetUsageRefresher::class)->refresh();

    expect($count)->toBe(2);
    Account::all()->each(
        fn (Account $account) => expect($account->probe_error)->not->toBeNull()
    );
});
