<?php

use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use App\Models\Boss;
use App\Models\Event;
use App\Models\User;
use App\Services\DamageTotals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->totals = app(DamageTotals::class);
    $this->boss = Boss::factory()->create();
});

test('global sums damage across rolling daily, monthly, and all-time windows', function () {
    $user = User::factory()->create();

    Event::factory()->create(['user_id' => $user->id, 'boss_id' => $this->boss->id, 'tokens' => 100, 'created_at' => now()->subHours(2)]);   // in all 3
    Event::factory()->create(['user_id' => $user->id, 'boss_id' => $this->boss->id, 'tokens' => 30, 'created_at' => now()->subDays(5)]);     // monthly + all-time
    Event::factory()->create(['user_id' => $user->id, 'boss_id' => $this->boss->id, 'tokens' => 7, 'created_at' => now()->subDays(45)]);     // all-time only

    expect($this->totals->global())->toBe([
        'allTime' => 137,
        'monthly' => 130,
        'daily' => 100,
        'hourly' => 0,
    ]);
});

test('global window boundaries are exclusive of events just outside them', function () {
    $user = User::factory()->create();

    Event::factory()->create(['user_id' => $user->id, 'boss_id' => $this->boss->id, 'tokens' => 5, 'created_at' => now()->subDay()->subMinute()]);    // just outside daily
    Event::factory()->create(['user_id' => $user->id, 'boss_id' => $this->boss->id, 'tokens' => 11, 'created_at' => now()->subDays(30)->subMinute()]); // just outside monthly

    expect($this->totals->global())->toBe([
        'allTime' => 16,
        'monthly' => 5,
        'daily' => 0,
        'hourly' => 0,
    ]);
});

test('forUser scopes totals to one user', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    Event::factory()->create(['user_id' => $alice->id, 'boss_id' => $this->boss->id, 'tokens' => 100, 'created_at' => now()->subMinutes(90)]);
    Event::factory()->create(['user_id' => $bob->id, 'boss_id' => $this->boss->id, 'tokens' => 999, 'created_at' => now()->subHour()]);

    expect($this->totals->forUser($alice))->toBe([
        'allTime' => 100,
        'monthly' => 100,
        'daily' => 100,
        'hourly' => 0,
    ]);
});

test('global is cached so a later event is not reflected until the cache expires', function () {
    $user = User::factory()->create();
    Event::factory()->create(['user_id' => $user->id, 'boss_id' => $this->boss->id, 'tokens' => 100, 'created_at' => now()->subHour()]);

    expect($this->totals->global()['allTime'])->toBe(100);

    Event::factory()->create(['user_id' => $user->id, 'boss_id' => $this->boss->id, 'tokens' => 50, 'created_at' => now()->subHour()]);
    expect($this->totals->global()['allTime'])->toBe(100); // still cached

    Cache::forget('damage-totals:global');
    expect($this->totals->global()['allTime'])->toBe(150);
});

test('totals are zero when no events exist', function () {
    expect($this->totals->global())->toBe(['allTime' => 0, 'monthly' => 0, 'daily' => 0, 'hourly' => 0]);
});

test('forUser returns zero totals when the user has no events', function () {
    $user = User::factory()->create();

    expect($this->totals->forUser($user))->toBe(['allTime' => 0, 'monthly' => 0, 'daily' => 0, 'hourly' => 0]);
});

test('hourly window counts only the last hour for global and forUser', function () {
    $user = User::factory()->create();

    Event::factory()->create(['user_id' => $user->id, 'boss_id' => $this->boss->id, 'tokens' => 40, 'created_at' => now()->subMinutes(30)]); // inside hour
    Event::factory()->create(['user_id' => $user->id, 'boss_id' => $this->boss->id, 'tokens' => 9, 'created_at' => now()->subHours(2)]);    // outside hour

    expect($this->totals->global()['hourly'])->toBe(40);
    expect($this->totals->forUser($user)['hourly'])->toBe(40);
});

test('forAccount sums tokens attributed to the account regardless of who logged them', function () {
    $account = Account::factory()->create();
    $other = Account::factory()->create();

    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $carol = User::factory()->create();

    Event::factory()->create(['user_id' => $alice->id, 'account_id' => $account->id, 'tokens' => 40, 'created_at' => now()->subMinutes(30)]); // hourly+daily+monthly
    Event::factory()->create(['user_id' => $bob->id, 'account_id' => $account->id, 'tokens' => 100, 'created_at' => now()->subHours(5)]);      // daily+monthly
    Event::factory()->create(['user_id' => $bob->id, 'account_id' => $account->id, 'tokens' => 7, 'created_at' => now()->subDays(10)]);        // monthly only
    Event::factory()->create(['user_id' => $carol->id, 'account_id' => $other->id, 'tokens' => 999, 'created_at' => now()->subMinutes(5)]); // other account

    expect($this->totals->forAccount($account))->toBe([
        'hourly' => 40,
        'daily' => 140,
        'monthly' => 147,
    ]);
});

test('forAccount returns zero totals when the account has no attributed events', function () {
    $account = Account::factory()->create();

    expect($this->totals->forAccount($account))->toBe(['hourly' => 0, 'daily' => 0, 'monthly' => 0]);
});

test('counts a user active on two accounts toward each account correctly', function () {
    [$a, $b] = Account::factory()->count(2)->create();
    $user = User::factory()->create();
    $user->accounts()->attach([$a->id, $b->id]);

    Event::factory()->create(['user_id' => $user->id, 'account_id' => $a->id, 'tokens' => 300]);
    Event::factory()->create(['user_id' => $user->id, 'account_id' => $b->id, 'tokens' => 700]);
    Event::factory()->create(['user_id' => $user->id, 'account_id' => null, 'tokens' => 11]); // personal

    $totals = app(DamageTotals::class);
    expect($totals->forAccount($a)['daily'])->toBe(300)
        ->and($totals->forAccount($b)['daily'])->toBe(700);

    $rows = collect($totals->perAccount());
    expect($rows->firstWhere('account_id', $a->id)['daily'])->toBe(300)
        ->and($rows->firstWhere('account_id', null)['daily'])->toBe(11);
});

test('perAccount returns per-window sums, pivot member counts, and an unassigned row', function () {
    $teamA = Account::factory()->create(['email' => 'team-a@example.com', 'plan' => 'max-20x']);
    $teamB = Account::factory()->create(['email' => 'team-b@example.com', 'plan' => 'max-20x']);

    $a1 = User::factory()->create();
    $a2 = User::factory()->create();
    $b1 = User::factory()->create();
    $loner = User::factory()->create();

    $teamA->users()->attach([$a1->id, $a2->id]);
    $teamB->users()->attach($b1->id);

    Event::factory()->create(['user_id' => $a1->id, 'account_id' => $teamA->id, 'tokens' => 40, 'created_at' => now()->subMinutes(30)]); // hourly
    Event::factory()->create(['user_id' => $a2->id, 'account_id' => $teamA->id, 'tokens' => 100, 'created_at' => now()->subHours(5)]);    // daily
    Event::factory()->create(['user_id' => $b1->id, 'account_id' => $teamB->id, 'tokens' => 10, 'created_at' => now()->subDays(3)]);      // monthly
    Event::factory()->create(['user_id' => $loner->id, 'account_id' => null, 'tokens' => 7, 'created_at' => now()->subMinutes(5)]); // unassigned hourly

    $rows = collect($this->totals->perAccount());

    $rowA = $rows->firstWhere('email', 'team-a@example.com');
    expect($rowA)->toMatchArray(['memberCount' => 2, 'hourly' => 40, 'daily' => 140, 'monthly' => 140]);

    $rowB = $rows->firstWhere('email', 'team-b@example.com');
    expect($rowB)->toMatchArray(['memberCount' => 1, 'hourly' => 0, 'daily' => 0, 'monthly' => 10]);

    $unassigned = $rows->firstWhere('email', '— unassigned —');
    expect($unassigned)->toMatchArray(['memberCount' => 1, 'hourly' => 7, 'daily' => 7, 'monthly' => 7]);
});

test('perUser returns per-window sums ordered by daily desc with the account email burned through', function () {
    $team = Account::factory()->create(['email' => 'team-a@example.com']);
    $heavy = User::factory()->create(['slack_handle' => 'heavy']);
    $light = User::factory()->create(['slack_handle' => 'light']);
    User::factory()->create(['slack_handle' => 'idle']); // no events → omitted

    Event::factory()->create(['user_id' => $heavy->id, 'account_id' => $team->id, 'tokens' => 200, 'created_at' => now()->subHours(2)]);  // daily
    Event::factory()->create(['user_id' => $heavy->id, 'account_id' => $team->id, 'tokens' => 20, 'created_at' => now()->subMinutes(10)]); // hourly
    Event::factory()->create(['user_id' => $light->id, 'account_id' => null, 'tokens' => 50, 'created_at' => now()->subHours(3)]);    // daily, no account

    $rows = $this->totals->perUser();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['handle'])->toBe('heavy')
        ->and($rows[0])->toMatchArray(['account_email' => 'team-a@example.com', 'hourly' => 20, 'daily' => 220, 'monthly' => 220])
        ->and($rows[1]['handle'])->toBe('light')
        ->and($rows[1]['account_email'])->toBeNull();
});

test('perUser aggregates every distinct account email a user burned tokens through in the window', function () {
    $accountA = Account::factory()->create(['email' => 'account-a@example.com']);
    $accountB = Account::factory()->create(['email' => 'account-b@example.com']);
    $user = User::factory()->create(['slack_handle' => 'wanderer']);

    Event::factory()->create(['user_id' => $user->id, 'account_id' => $accountA->id, 'tokens' => 10, 'created_at' => now()->subHour()]);
    Event::factory()->create(['user_id' => $user->id, 'account_id' => $accountB->id, 'tokens' => 20, 'created_at' => now()->subHour()]);
    Event::factory()->create(['user_id' => $user->id, 'account_id' => $accountA->id, 'tokens' => 5, 'created_at' => now()->subHour()]);

    $rows = $this->totals->perUser();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['account_email'])->toBe('account-a@example.com, account-b@example.com');
});

test('forUserByAccount returns rows for accounts the user is a member of or has events with', function () {
    $memberOnly = Account::factory()->create(['email' => 'member-only@example.com', 'name' => 'Member Only', 'plan' => 'max-20x']);
    $eventsOnly = Account::factory()->create(['email' => 'events-only@example.com', 'name' => 'Events Only', 'plan' => 'max-5x']);
    $user = User::factory()->create();
    $other = User::factory()->create();

    $memberOnly->users()->attach($user->id);
    $eventsOnly->users()->attach($other->id);

    Event::factory()->create(['user_id' => $user->id, 'account_id' => $eventsOnly->id, 'tokens' => 30, 'created_at' => now()->subMinutes(20)]);
    Event::factory()->create(['user_id' => $user->id, 'account_id' => $eventsOnly->id, 'tokens' => 15, 'created_at' => now()->subDays(2)]);

    $rows = collect($this->totals->forUserByAccount($user));

    expect($rows)->toHaveCount(2);

    $memberRow = $rows->firstWhere('account_id', $memberOnly->id);
    expect($memberRow)->toMatchArray([
        'email' => 'member-only@example.com',
        'name' => 'Member Only',
        'plan' => 'max-20x',
        'memberCount' => 1,
        'isMember' => true,
        'hourly' => 0,
        'daily' => 0,
        'monthly' => 0,
    ]);

    $eventsRow = $rows->firstWhere('account_id', $eventsOnly->id);
    expect($eventsRow)->toMatchArray([
        'email' => 'events-only@example.com',
        'name' => 'Events Only',
        'plan' => 'max-5x',
        'memberCount' => 1,
        'isMember' => false,
        'hourly' => 30,
        'daily' => 30,
        'monthly' => 45,
    ]);
});

test('forUserByAccount carries the latest usage snapshot util for each account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['email' => 'quota@example.com']);
    $account->users()->attach($user->id);

    AccountUsageSnapshot::factory()->for($account)->create([
        'util_5h' => 10, 'util_7d' => 20, 'created_at' => now()->subHour(),
    ]);
    AccountUsageSnapshot::factory()->for($account)->create([
        'util_5h' => 42, 'util_7d' => 88, 'created_at' => now()->subMinute(),
    ]);

    $row = collect($this->totals->forUserByAccount($user))->firstWhere('account_id', $account->id);

    expect($row['util_5h'])->toBe(42)
        ->and($row['util_7d'])->toBe(88)
        ->and($row['lastProbedAt'])->not->toBeNull();
});

test('forUserByAccount leaves util null when the account was never probed', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create();
    $account->users()->attach($user->id);

    $row = collect($this->totals->forUserByAccount($user))->firstWhere('account_id', $account->id);

    expect($row['util_5h'])->toBeNull()
        ->and($row['util_7d'])->toBeNull()
        ->and($row['lastProbedAt'])->toBeNull();
});
