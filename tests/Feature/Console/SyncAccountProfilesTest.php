<?php

use App\Enums\AccountStatus;
use App\Models\Account;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('happy path updates plan, account_uuid, and organization_uuid from a matching profile', function () {
    fakeAnthropic();

    $account = Account::factory()->connected()->create([
        'email' => 'ongtung2212002@gmail.com',
        'plan' => 'max-20x',
        'account_uuid' => null,
        'organization_uuid' => null,
    ]);

    $this->artisan('accounts:sync-profiles')->assertSuccessful();

    $account->refresh();

    expect($account->plan)->toBe('claude_pro')
        ->and($account->account_uuid)->toBe('adfeaf9f-dd9c-4c03-93c2-0bb05c7278b9')
        ->and($account->organization_uuid)->toBe('7f993a12-f480-45cd-8b99-1e3182d168bf')
        ->and($account->probe_error)->toBeNull();
});

test('a mismatched profile email records a probe_error and leaves plan and uuids unchanged', function () {
    fakeAnthropic();

    $account = Account::factory()->connected()->create([
        'email' => 'someone-else@example.com',
        'plan' => 'max-20x',
        'account_uuid' => 'original-uuid',
        'organization_uuid' => 'original-org-uuid',
    ]);

    $this->artisan('accounts:sync-profiles')->assertSuccessful();

    $account->refresh();

    expect($account->probe_error)->toBe('profile email mismatch: ongtung2212002@gmail.com')
        ->and($account->plan)->toBe('max-20x')
        ->and($account->account_uuid)->toBe('original-uuid')
        ->and($account->organization_uuid)->toBe('original-org-uuid');
});

test('a disabled account is skipped without an HTTP call', function () {
    Http::preventStrayRequests();
    Http::fake();

    $account = Account::factory()->connected()->create(['status' => AccountStatus::Disabled]);

    $this->artisan('accounts:sync-profiles')->assertSuccessful();

    Http::assertNothingSent();
    expect($account->fresh()->probe_error)->toBeNull();
});

test('a profile API error records a safe probe_error without crashing', function () {
    fakeAnthropic(['profile' => Http::response('', 401)]);

    $account = Account::factory()->connected()->create([
        'email' => 'ongtung2212002@gmail.com',
        'plan' => 'max-20x',
    ]);

    $this->artisan('accounts:sync-profiles')->assertSuccessful();

    expect($account->fresh()->probe_error)->toBe('profile sync failed: unauthorized')
        ->and($account->fresh()->plan)->toBe('max-20x');
});

test('an organization uuid collision records a claimed-elsewhere probe_error without throwing', function () {
    fakeAnthropic();

    Account::factory()->connected()->withOrganizationUuid('7f993a12-f480-45cd-8b99-1e3182d168bf')->create([
        'email' => 'other-owner@example.com',
    ]);

    $account = Account::factory()->connected()->create([
        'email' => 'ongtung2212002@gmail.com',
        'plan' => 'max-20x',
        'account_uuid' => null,
        'organization_uuid' => null,
    ]);

    $this->artisan('accounts:sync-profiles')->assertSuccessful();

    $account->refresh();

    expect($account->probe_error)->toBe('org uuid already claimed')
        ->and($account->organization_uuid)->toBeNull()
        ->and($account->plan)->toBe('claude_pro')
        ->and($account->account_uuid)->toBe('adfeaf9f-dd9c-4c03-93c2-0bb05c7278b9');
});

test('reports a summary line with synced, mismatched, and error counts', function () {
    fakeAnthropic();

    Account::factory()->connected()->create(['email' => 'ongtung2212002@gmail.com']);
    Account::factory()->connected()->create(['email' => 'mismatch@example.com']);

    $this->artisan('accounts:sync-profiles')
        ->expectsOutputToContain('synced 1, mismatched 1, errors 0')
        ->assertSuccessful();
});

test('accounts:sync-profiles is scheduled daily without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command, 'accounts:sync-profiles'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

test('sync skips needs_reauth accounts so their re-auth signal is preserved', function () {
    fakeAnthropic();

    $account = Account::factory()->needsReauth()->create([
        'email' => 'ongtung2212002@gmail.com',
        'probe_error' => 'refresh failed: invalid_grant',
    ]);

    $this->artisan('accounts:sync-profiles')->assertSuccessful();

    expect($account->refresh()->probe_error)->toBe('refresh failed: invalid_grant');
    Http::assertNothingSent();
});
