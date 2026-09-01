<?php
// tests/Feature/Provisioning/ExpiringAccountsQueryTest.php

use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\User;
use App\Services\Accounts\ExpiringAccountsQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns accounts within the 3-day warning window AND already-expired ones, most-urgent first', function () {
    $soon = Account::factory()->create(['oauth_refresh_expires_at' => now()->addHours(6), 'email' => 'soon@x.com']);
    $later = Account::factory()->create(['oauth_refresh_expires_at' => now()->addDays(2), 'email' => 'later@x.com']);
    Account::factory()->create(['oauth_refresh_expires_at' => now()->addDays(10)]); // outside window -- still healthy, correctly excluded
    Account::factory()->create(['oauth_refresh_expires_at' => null]); // never set -- unknown, correctly excluded (see spec's own noted gap)
    $dead = Account::factory()->create(['oauth_refresh_expires_at' => now()->subDay(), 'email' => 'dead@x.com']); // already past -- must NOT vanish, this is the most urgent row

    $rows = app(ExpiringAccountsQuery::class)->get();

    // Already-past sorts first (ascending timestamp order naturally puts the
    // oldest/most-overdue deadline first), then soonest-still-alive, then later.
    expect(array_column($rows, 'email'))->toBe(['dead@x.com', 'soon@x.com', 'later@x.com'])
        ->and($rows[0]['is_expired'])->toBeTrue()
        ->and($rows[1]['is_expired'])->toBeFalse()
        ->and($rows[2]['is_expired'])->toBeFalse();
});

it('counts only tracked members per account', function () {
    $account = Account::factory()->create(['oauth_refresh_expires_at' => now()->addHours(6)]);
    $tracked = User::factory()->create();
    $untracked = User::factory()->create();
    $account->users()->attach($tracked->id, ['status' => MembershipStatus::Tracked->value]);
    $account->users()->attach($untracked->id, ['status' => MembershipStatus::Untracked->value]);

    $rows = app(ExpiringAccountsQuery::class)->get();

    expect($rows[0]['tracked_members'])->toBe(1);
});

it('count() matches get()s row count without hydrating rows', function () {
    Account::factory()->create(['oauth_refresh_expires_at' => now()->addHours(6)]);
    Account::factory()->create(['oauth_refresh_expires_at' => now()->subDay()]);
    Account::factory()->create(['oauth_refresh_expires_at' => now()->addDays(10)]); // outside window

    $query = app(ExpiringAccountsQuery::class);

    expect($query->count())->toBe(count($query->get()))->toBe(2);
});
