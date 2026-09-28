<?php

use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\CodexCredential;
use App\Services\Analytics\FleetUsageRefresher;
use App\Services\DamageTotals;
use App\Services\ProviderServiceFactory;
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

it('also probes probeable Codex accounts via CodexUsageProber, not just Claude', function (): void {
    fakeAnthropic();
    Account::factory()->connected()->create(['status' => AccountStatus::Active]);
    $codex = Account::factory()->create(['provider' => 'codex']);
    CodexCredential::factory()->for($codex)->create(['codex_access_token' => 'fake-token']);
    $fixture = json_decode(file_get_contents(base_path('tests/fixtures/codex/usage.json')), true);
    Http::fake(['chatgpt.com/backend-api/wham/usage' => Http::response($fixture, 200)]);

    $count = app(FleetUsageRefresher::class)->refresh();

    expect($count)->toBe(2)
        ->and($codex->fresh()->usageSnapshots()->count())->toBeGreaterThan(0);
});

it('skips a Codex account with no access token, without counting it as probed', function (): void {
    $codex = Account::factory()->create(['provider' => 'codex']);
    CodexCredential::factory()->for($codex)->create(['codex_access_token' => null]);

    $count = app(FleetUsageRefresher::class)->refresh();

    expect($count)->toBe(0);
    Http::assertNothingSent();
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

it('skips an account whose probe lock is already held by someone else', function (): void {
    $held = Account::factory()->create();
    $free = Account::factory()->create();
    Cache::lock("account-probe:{$held->id}", 60)->get();
    $probed = [];

    app()->instance(ProviderServiceFactory::class, fakeProberFactory(function (Account $account) use (&$probed) {
        $probed[] = $account->id;
    }));

    $count = app(FleetUsageRefresher::class)->refreshAccounts(collect([$held, $free]));

    expect($count)->toBe(1)->and($probed)->toBe([$free->id]);
});

it('refreshAccounts probes only the accounts it is given', function (): void {
    $chosen = Account::factory()->create();
    Account::factory()->create(); // not passed in — must never be probed
    $probed = [];

    app()->instance(ProviderServiceFactory::class, fakeProberFactory(function (Account $account) use (&$probed) {
        $probed[] = $account->id;
    }));

    $count = app(FleetUsageRefresher::class)->refreshAccounts(collect([$chosen]));

    expect($count)->toBe(1)->and($probed)->toBe([$chosen->id]);
});
