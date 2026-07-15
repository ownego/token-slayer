<?php

use App\Models\Account;
use App\Models\Event;
use App\Models\User;
use App\Services\Attribution\UnrecognizedAccountsQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it lists distinct org beacons with no matched account, aggregated, matched flag set only when an account exists', function () {
    $user = User::factory()->create();
    $matched = Account::factory()->withOrganizationUuid('org-matched')->create(['email' => 'known@example.com']);

    // Two unrecognized events for an org that HAS an account (predate it) — account_id still null.
    Event::factory()->count(2)->create(['account_id' => null, 'account_org_id' => 'org-matched', 'user_id' => $user->id, 'tokens' => 100]);
    // Three unrecognized events for an org with NO account.
    Event::factory()->count(3)->create(['account_id' => null, 'account_org_id' => 'org-unknown', 'user_id' => $user->id, 'tokens' => 50]);
    // Noise that must NOT appear: already attributed, and no-beacon.
    Event::factory()->create(['account_id' => $matched->id, 'account_org_id' => 'org-matched', 'user_id' => $user->id, 'tokens' => 999]);
    Event::factory()->create(['account_id' => null, 'account_org_id' => null, 'user_id' => $user->id, 'tokens' => 999]);

    $rows = app(UnrecognizedAccountsQuery::class)->get();

    expect($rows)->toHaveCount(2);

    $unknown = collect($rows)->firstWhere('org_uuid', 'org-unknown');
    expect($unknown['events'])->toBe(3)
        ->and($unknown['tokens'])->toBe(150)
        ->and($unknown['users'])->toBe(1)
        ->and($unknown['account_id'])->toBeNull()
        ->and($unknown['account_email'])->toBeNull();

    $matchedRow = collect($rows)->firstWhere('org_uuid', 'org-matched');
    expect($matchedRow['events'])->toBe(2)
        ->and($matchedRow['tokens'])->toBe(200)
        ->and($matchedRow['account_id'])->toBe($matched->id)
        ->and($matchedRow['account_email'])->toBe('known@example.com');
});

test('it returns an empty array when every event is attributed or has no beacon', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create();
    Event::factory()->create(['account_id' => $account->id, 'account_org_id' => 'org-x', 'user_id' => $user->id]);
    Event::factory()->create(['account_id' => null, 'account_org_id' => null, 'user_id' => $user->id]);

    expect(app(UnrecognizedAccountsQuery::class)->get())->toBe([]);
});

it('orders unrecognized organizations by last seen descending', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    // org "recent" last event now; org "stale" last event a day ago.
    // Uuids are chosen so alphabetical order is the OPPOSITE of recency order:
    // this way a stale COUNT(*)-DESC tie-break (which falls back to something
    // alphabetical-ish) would produce org-a-stale first, while the real
    // MAX(created_at) DESC ordering puts org-z-recent first.
    Event::factory()->for($userA)->create(['account_id' => null, 'account_org_id' => 'org-a-stale', 'created_at' => now()->subDay()]);
    Event::factory()->for($userB)->create(['account_id' => null, 'account_org_id' => 'org-z-recent', 'created_at' => now()]);

    $rows = app(UnrecognizedAccountsQuery::class)->get();

    expect(collect($rows)->pluck('org_uuid')->all())->toBe(['org-z-recent', 'org-a-stale']);
});
