<?php

use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('probes only active accounts that have a refresh token', function () {
    fakeAnthropic();

    $probeable = Account::factory()->connected()->create();
    $disabled = Account::factory()->connected()->create(['status' => AccountStatus::Disabled]);
    $needsReauth = Account::factory()->needsReauth()->create();
    $tokenless = Account::factory()->create();

    $this->artisan('accounts:probe')->assertSuccessful();

    expect(AccountUsageSnapshot::count())->toBe(1)
        ->and(AccountUsageSnapshot::first()->account_id)->toBe($probeable->id);

    expect($probeable->fresh()->last_probed_at)->not->toBeNull()
        ->and($disabled->fresh()->last_probed_at)->toBeNull()
        ->and($needsReauth->fresh()->last_probed_at)->toBeNull()
        ->and($tokenless->fresh()->last_probed_at)->toBeNull();
});

test('reports a summary line with the probed and recorded counts', function () {
    fakeAnthropic();
    Account::factory()->connected()->create();
    Account::factory()->connected()->create();

    $this->artisan('accounts:probe')
        ->expectsOutputToContain('probed 2 accounts, 2 snapshots')
        ->assertSuccessful();
});

test('a probe that records no snapshot is still counted without aborting the batch', function () {
    fakeAnthropic(['usage' => Http::response('', 500)]);
    Account::factory()->connected()->create();
    Account::factory()->connected()->create();

    $this->artisan('accounts:probe')
        ->expectsOutputToContain('probed 2 accounts, 0 snapshots')
        ->assertSuccessful();
});

test('Account::probeable scope returns only active accounts with a refresh token', function () {
    $probeable = Account::factory()->connected()->create();
    Account::factory()->connected()->create(['status' => AccountStatus::Disabled]);
    Account::factory()->needsReauth()->create();
    Account::factory()->create();

    expect(Account::probeable()->pluck('id')->all())->toBe([$probeable->id]);
});

test('accounts:probe is scheduled every five minutes without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command, 'accounts:probe'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
