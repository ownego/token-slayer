<?php

use App\Enums\AccountStatus;
use App\Enums\MembershipStatus;
use App\Enums\Provider;
use App\Models\Account;
use App\Models\AccountProvisionedGrant;
use App\Models\ClaudeCredential;
use App\Models\CodexCredential;
use App\Models\Device;
use App\Models\Event;
use App\Models\User;
use App\Services\Attribution\ExpiringAccountsQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('includes a claude account whose refresh token expires within 3 days', function (): void {
    $account = Account::create(['email' => 'soon@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $account->id, 'oauth_refresh_expires_at' => now()->addDays(2)]);

    $rows = app(ExpiringAccountsQuery::class)->get();

    expect(collect($rows)->pluck('account_id'))->toContain($account->id);
    $row = collect($rows)->firstWhere('account_id', $account->id);
    expect($row['provider'])->toBe(Provider::Claude)
        ->and($row['deadline'])->not->toBeNull();
});

it('excludes a claude account whose refresh token does not expire soon', function (): void {
    $account = Account::create(['email' => 'safe@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $account->id, 'oauth_refresh_expires_at' => now()->addDays(20)]);

    $rows = app(ExpiringAccountsQuery::class)->get();

    expect(collect($rows)->pluck('account_id'))->not->toContain($account->id);
});

it('excludes a claude account with no refresh_expires_at at all', function (): void {
    $account = Account::create(['email' => 'unknown@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $account->id]);

    $rows = app(ExpiringAccountsQuery::class)->get();

    expect(collect($rows)->pluck('account_id'))->not->toContain($account->id);
});

it('includes a codex account whose earliest_refresh_at has passed', function (): void {
    $account = Account::create(['email' => 'codex-stale@example.com', 'provider' => 'codex']);
    CodexCredential::create(['account_id' => $account->id, 'earliest_refresh_at' => now()->subHour()]);

    $rows = app(ExpiringAccountsQuery::class)->get();
    $row = collect($rows)->firstWhere('account_id', $account->id);

    expect($row)->not->toBeNull()
        ->and($row['provider'])->toBe(Provider::Codex)
        ->and($row['deadline'])->toBeNull();
});

it('includes a codex account with no earliest_refresh_at whose last_refreshed_at is over 8 days old', function (): void {
    $account = Account::create(['email' => 'codex-old@example.com', 'provider' => 'codex']);
    CodexCredential::create(['account_id' => $account->id, 'last_refreshed_at' => now()->subDays(9)]);

    $rows = app(ExpiringAccountsQuery::class)->get();

    expect(collect($rows)->pluck('account_id'))->toContain($account->id);
});

it('excludes a codex account refreshed recently with no earliest_refresh_at', function (): void {
    $account = Account::create(['email' => 'codex-fresh@example.com', 'provider' => 'codex']);
    CodexCredential::create(['account_id' => $account->id, 'last_refreshed_at' => now()->subDays(2)]);

    $rows = app(ExpiringAccountsQuery::class)->get();

    expect(collect($rows)->pluck('account_id'))->not->toContain($account->id);
});

it('includes a claude account already flagged as needing re-auth', function (): void {
    // The grant is dead now, not soon. Without this the sidebar badge reads 0
    // while every account is broken: a dead account is dropped from the probe
    // batch, so its deadline freezes and only drifts into the 3-day window
    // weeks later — and never at all when it died before that deadline was
    // ever captured, which is the state every real account is in.
    $account = Account::create(['email' => 'dead@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $account->id, 'status' => AccountStatus::NeedsReauth]);

    $row = collect(app(ExpiringAccountsQuery::class)->get())->firstWhere('account_id', $account->id);

    expect($row)->not->toBeNull()
        ->and($row['label'])->toContain('re-auth')
        ->and($row['deadline'])->toBeNull();
});

it('includes a claude account that has not refreshed successfully in over 2 days', function (): void {
    // A healthy grant refreshes every few hours, so a two-day gap means the
    // refresh has been failing in a way that does not flip the status —
    // a rate limit or a network error, both of which leave it Active on
    // purpose so the next cycle retries. Catching it here is ~26 days of
    // warning that waiting for the frozen deadline would not give.
    $account = Account::create(['email' => 'stalled@example.com', 'provider' => 'claude']);
    ClaudeCredential::create([
        'account_id' => $account->id,
        'oauth_refresh_expires_at' => now()->addDays(20),
        'last_refreshed_at' => now()->subDays(3),
    ]);

    $row = collect(app(ExpiringAccountsQuery::class)->get())->firstWhere('account_id', $account->id);

    expect($row)->not->toBeNull()
        ->and($row['label'])->toContain("hasn't refreshed");
});

it('leaves a claude account alone while its refresh is still rotating', function (): void {
    $account = Account::create(['email' => 'rotating@example.com', 'provider' => 'claude']);
    ClaudeCredential::create([
        'account_id' => $account->id,
        'oauth_refresh_expires_at' => now()->addDays(20),
        'last_refreshed_at' => now()->subHours(6),
    ]);

    expect(collect(app(ExpiringAccountsQuery::class)->get())->pluck('account_id'))
        ->not->toContain($account->id);
});

it('does not call a claude account stale merely for never having recorded a refresh', function (): void {
    // last_refreshed_at is null on every row that predates the column. Reading
    // that as "stale" would flag the whole fleet the moment this ships, which
    // is the same as flagging nothing.
    $account = Account::create(['email' => 'legacy@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $account->id, 'oauth_refresh_expires_at' => now()->addDays(20)]);

    expect(collect(app(ExpiringAccountsQuery::class)->get())->pluck('account_id'))
        ->not->toContain($account->id);
});

it('leaves a disabled claude account out of the list entirely', function (): void {
    // Disabled is a deliberate admin choice; it is not something to repair,
    // so it must not sit in the badge count nagging forever.
    $account = Account::create(['email' => 'off@example.com', 'provider' => 'claude']);
    ClaudeCredential::create([
        'account_id' => $account->id,
        'status' => AccountStatus::Disabled,
        'last_refreshed_at' => now()->subDays(30),
        'oauth_refresh_expires_at' => now()->addDay(),
    ]);

    expect(collect(app(ExpiringAccountsQuery::class)->get())->pluck('account_id'))
        ->not->toContain($account->id);
});

it('flags a fresh pending grant on an expiring claude account', function (): void {
    // The admin's own worry: they already reissued a grant for this expiring
    // account, but the employee has not pulled it yet. Nothing here should
    // read as "still untouched" while a fresh grant is sitting there.
    $account = Account::create(['email' => 'soon@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $account->id, 'oauth_refresh_expires_at' => now()->addDay()]);
    AccountProvisionedGrant::factory()
        ->for($account)
        ->pending()
        ->create(['provisioned_at' => now()->subHours(2)]);

    $rows = app(ExpiringAccountsQuery::class)->get();

    $row = collect($rows)->firstWhere('account_id', $account->id);
    expect($row['has_fresh_pending_grant'])->toBeTrue();
});

it('does not flag a pending grant that has gone stale past the 24h badge window', function (): void {
    $account = Account::create(['email' => 'soon@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $account->id, 'oauth_refresh_expires_at' => now()->addDay()]);
    AccountProvisionedGrant::factory()
        ->for($account)
        ->pending()
        ->create(['provisioned_at' => now()->subHours(25)]);

    $rows = app(ExpiringAccountsQuery::class)->get();

    $row = collect($rows)->firstWhere('account_id', $account->id);
    expect($row['has_fresh_pending_grant'])->toBeFalse();
});

it('does not flag an expiring account with no grant at all', function (): void {
    $account = Account::create(['email' => 'soon@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $account->id, 'oauth_refresh_expires_at' => now()->addDay()]);

    $rows = app(ExpiringAccountsQuery::class)->get();

    $row = collect($rows)->firstWhere('account_id', $account->id);
    expect($row['has_fresh_pending_grant'])->toBeFalse();
});

it('does not flag an expiring account whose only grant is revoked', function (): void {
    $account = Account::create(['email' => 'soon@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $account->id, 'oauth_refresh_expires_at' => now()->addDay()]);
    AccountProvisionedGrant::factory()->for($account)->revoked()->create();

    $rows = app(ExpiringAccountsQuery::class)->get();

    $row = collect($rows)->firstWhere('account_id', $account->id);
    expect($row['has_fresh_pending_grant'])->toBeFalse();
});

it('flags a claimed grant whose own session is about to expire even though the shared credential is healthy', function (): void {
    // The shared credential is what the server's own probe refreshes, and
    // one live device is enough to keep it looking healthy — that is the
    // exact scenario that let a member's own session die with no warning.
    $account = Account::create(['email' => 'shared@example.com', 'provider' => 'claude']);
    ClaudeCredential::create([
        'account_id' => $account->id,
        'oauth_refresh_expires_at' => now()->addDays(20),
        'last_refreshed_at' => now()->subHour(),
    ]);
    $user = User::factory()->create();
    $device = Device::factory()->for($user)->create();
    $account->users()->syncWithoutDetaching([$user->id => ['status' => MembershipStatus::Tracked->value]]);
    AccountProvisionedGrant::factory()->for($account)->for($device)->claimed()->create([
        'session_expires_at' => now()->addDay(),
        'session_expires_at_estimated' => false,
    ]);

    $rows = collect(app(ExpiringAccountsQuery::class)->get());
    $grantRow = $rows->first(fn (array $row): bool => $row['account_id'] === $account->id && $row['kind'] === 'grant');

    expect($grantRow)->not->toBeNull()
        ->and($grantRow['deadline'])->not->toBeNull();
});

it('leaves a claimed grant alone while its own session is not expiring soon', function (): void {
    $account = Account::create(['email' => 'shared2@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $account->id, 'oauth_refresh_expires_at' => now()->addDays(20)]);
    $user = User::factory()->create();
    $device = Device::factory()->for($user)->create();
    AccountProvisionedGrant::factory()->for($account)->for($device)->claimed()->create([
        'session_expires_at' => now()->addDays(20),
    ]);

    $rows = collect(app(ExpiringAccountsQuery::class)->get());

    expect($rows->contains(fn (array $row): bool => $row['kind'] === 'grant'))->toBeFalse();
});

it('never flags a revoked grant for its own session deadline', function (): void {
    $account = Account::create(['email' => 'shared3@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $account->id, 'oauth_refresh_expires_at' => now()->addDays(20)]);
    $user = User::factory()->create();
    $device = Device::factory()->for($user)->create();
    AccountProvisionedGrant::factory()->for($account)->for($device)->revoked()->create([
        'session_expires_at' => now()->addDay(),
    ]);

    $rows = collect(app(ExpiringAccountsQuery::class)->get());

    expect($rows->contains(fn (array $row): bool => $row['kind'] === 'grant'))->toBeFalse();
});

it('does not trust an estimated session deadline once real activity outlived it', function (): void {
    // A backfilled guess that the account's own later events already
    // disproved is worse than no signal at all — it would cry wolf forever.
    $account = Account::create(['email' => 'estimated1@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $account->id, 'oauth_refresh_expires_at' => now()->addDays(20)]);
    $user = User::factory()->create();
    $device = Device::factory()->for($user)->create();
    $grant = AccountProvisionedGrant::factory()->for($account)->for($device)->claimed()->create([
        'session_expires_at' => now()->subDay(),
        'session_expires_at_estimated' => true,
    ]);
    Event::factory()->for($account)->for($user)->create(['created_at' => now()]);

    $rows = collect(app(ExpiringAccountsQuery::class)->get());

    expect($rows->contains(fn (array $row): bool => $row['kind'] === 'grant'))->toBeFalse();
});

it('trusts an estimated session deadline when nothing contradicts it', function (): void {
    $account = Account::create(['email' => 'estimated2@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $account->id, 'oauth_refresh_expires_at' => now()->addDays(20)]);
    $user = User::factory()->create();
    $device = Device::factory()->for($user)->create();
    $account->users()->syncWithoutDetaching([$user->id => ['status' => MembershipStatus::Tracked->value]]);
    AccountProvisionedGrant::factory()->for($account)->for($device)->claimed()->create([
        'session_expires_at' => now()->addDay(),
        'session_expires_at_estimated' => true,
    ]);
    Event::factory()->for($account)->for($user)->create(['created_at' => now()->subDays(5)]);

    $rows = collect(app(ExpiringAccountsQuery::class)->get());

    expect($rows->contains(fn (array $row): bool => $row['kind'] === 'grant'))->toBeTrue();
});

it('does not flag a grant whose holder is untracked on the account', function (): void {
    // The grant itself was never revoked when the member left the account,
    // so it is still "live" by grant status alone — but nagging an admin to
    // reissue a session for someone who is no longer on this account at all
    // is worse than useless.
    $account = Account::create(['email' => 'left@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $account->id, 'oauth_refresh_expires_at' => now()->addDays(20)]);
    $user = User::factory()->create();
    $device = Device::factory()->for($user)->create();
    $account->users()->syncWithoutDetaching([$user->id => ['status' => MembershipStatus::Untracked->value]]);
    AccountProvisionedGrant::factory()->for($account)->for($device)->claimed()->create([
        'session_expires_at' => now()->addDay(),
    ]);

    $rows = collect(app(ExpiringAccountsQuery::class)->get());

    expect($rows->contains(fn (array $row): bool => $row['kind'] === 'grant'))->toBeFalse();
});

it('does not flag a grant whose holder has no membership row on the account at all', function (): void {
    $account = Account::create(['email' => 'nomembership@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $account->id, 'oauth_refresh_expires_at' => now()->addDays(20)]);
    $user = User::factory()->create();
    $device = Device::factory()->for($user)->create();
    AccountProvisionedGrant::factory()->for($account)->for($device)->claimed()->create([
        'session_expires_at' => now()->addDay(),
    ]);

    $rows = collect(app(ExpiringAccountsQuery::class)->get());

    expect($rows->contains(fn (array $row): bool => $row['kind'] === 'grant'))->toBeFalse();
});

it('still flags a grant whose holder is only Pending on the account', function (): void {
    $account = Account::create(['email' => 'pendingmember@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $account->id, 'oauth_refresh_expires_at' => now()->addDays(20)]);
    $user = User::factory()->create();
    $device = Device::factory()->for($user)->create();
    $account->users()->syncWithoutDetaching([$user->id => ['status' => MembershipStatus::Pending->value]]);
    AccountProvisionedGrant::factory()->for($account)->for($device)->claimed()->create([
        'session_expires_at' => now()->addDay(),
    ]);

    $rows = collect(app(ExpiringAccountsQuery::class)->get());

    expect($rows->contains(fn (array $row): bool => $row['kind'] === 'grant'))->toBeTrue();
});
