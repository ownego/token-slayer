<?php

use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use App\Models\CodexCredential;
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

test('also probes probeable Codex accounts via CodexUsageProber', function () {
    fakeAnthropic();
    Account::factory()->connected()->create();
    $codex = Account::factory()->create(['provider' => 'codex']);
    CodexCredential::factory()->for($codex)->create(['codex_access_token' => 'fake-token']);
    $fixture = json_decode(file_get_contents(base_path('tests/fixtures/codex/usage.json')), true);
    Http::fake(['chatgpt.com/backend-api/wham/usage' => Http::response($fixture, 200)]);

    $this->artisan('accounts:probe')
        ->expectsOutputToContain('probed 2 accounts, 2 snapshots')
        ->assertSuccessful();

    expect($codex->fresh()->last_probed_at)->not->toBeNull();
});

test('Account::codexProbeable scope returns only active Codex accounts with an access token', function () {
    $probeable = Account::factory()->create(['provider' => 'codex']);
    CodexCredential::factory()->for($probeable)->create(['codex_access_token' => 'fake-token']);
    $disabled = Account::factory()->create(['provider' => 'codex']);
    CodexCredential::factory()->for($disabled)->create(['codex_access_token' => 'fake-token', 'status' => AccountStatus::Disabled]);
    $tokenless = Account::factory()->create(['provider' => 'codex']);
    CodexCredential::factory()->for($tokenless)->create(['codex_access_token' => null]);

    expect(Account::codexProbeable()->pluck('id')->all())->toBe([$probeable->id]);
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
