<?php

use App\Models\Account;
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

test('forAccount sums tokens across the account members over rolling windows', function () {
    $account = Account::factory()->create();
    $other = Account::factory()->create();

    $alice = User::factory()->create(['account_id' => $account->id]);
    $bob = User::factory()->create(['account_id' => $account->id]);
    $carol = User::factory()->create(['account_id' => $other->id]);

    Event::factory()->create(['user_id' => $alice->id, 'tokens' => 40, 'created_at' => now()->subMinutes(30)]); // hourly+daily+monthly
    Event::factory()->create(['user_id' => $bob->id, 'tokens' => 100, 'created_at' => now()->subHours(5)]);      // daily+monthly
    Event::factory()->create(['user_id' => $bob->id, 'tokens' => 7, 'created_at' => now()->subDays(10)]);        // monthly only
    Event::factory()->create(['user_id' => $carol->id, 'tokens' => 999, 'created_at' => now()->subMinutes(5)]); // other account

    expect($this->totals->forAccount($account))->toBe([
        'hourly' => 40,
        'daily' => 140,
        'monthly' => 147,
    ]);
});

test('forAccount returns zero totals when the account has no events', function () {
    $account = Account::factory()->create();
    User::factory()->create(['account_id' => $account->id]);

    expect($this->totals->forAccount($account))->toBe(['hourly' => 0, 'daily' => 0, 'monthly' => 0]);
});

test('perAccount returns per-window sums, member counts, and an unassigned row', function () {
    $teamA = Account::factory()->create(['email' => 'team-a@example.com', 'plan' => 'max-20x']);
    $teamB = Account::factory()->create(['email' => 'team-b@example.com', 'plan' => 'max-20x']);

    $a1 = User::factory()->create(['account_id' => $teamA->id]);
    $a2 = User::factory()->create(['account_id' => $teamA->id]);
    $b1 = User::factory()->create(['account_id' => $teamB->id]);
    $loner = User::factory()->create(['account_id' => null]);

    Event::factory()->create(['user_id' => $a1->id, 'tokens' => 40, 'created_at' => now()->subMinutes(30)]); // hourly
    Event::factory()->create(['user_id' => $a2->id, 'tokens' => 100, 'created_at' => now()->subHours(5)]);    // daily
    Event::factory()->create(['user_id' => $b1->id, 'tokens' => 10, 'created_at' => now()->subDays(3)]);      // monthly
    Event::factory()->create(['user_id' => $loner->id, 'tokens' => 7, 'created_at' => now()->subMinutes(5)]); // unassigned hourly

    $rows = collect($this->totals->perAccount());

    $rowA = $rows->firstWhere('email', 'team-a@example.com');
    expect($rowA)->toMatchArray(['memberCount' => 2, 'hourly' => 40, 'daily' => 140, 'monthly' => 140]);

    $rowB = $rows->firstWhere('email', 'team-b@example.com');
    expect($rowB)->toMatchArray(['memberCount' => 1, 'hourly' => 0, 'daily' => 0, 'monthly' => 10]);

    $unassigned = $rows->firstWhere('email', '— unassigned —');
    expect($unassigned)->toMatchArray(['memberCount' => 1, 'hourly' => 7, 'daily' => 7, 'monthly' => 7]);
});

test('perUser returns per-window sums ordered by daily desc with account name', function () {
    $team = Account::factory()->create(['email' => 'team-a@example.com']);
    $heavy = User::factory()->create(['account_id' => $team->id, 'slack_handle' => 'heavy']);
    $light = User::factory()->create(['account_id' => null, 'slack_handle' => 'light']);
    User::factory()->create(['slack_handle' => 'idle']); // no events → omitted

    Event::factory()->create(['user_id' => $heavy->id, 'tokens' => 200, 'created_at' => now()->subHours(2)]);  // daily
    Event::factory()->create(['user_id' => $heavy->id, 'tokens' => 20, 'created_at' => now()->subMinutes(10)]); // hourly
    Event::factory()->create(['user_id' => $light->id, 'tokens' => 50, 'created_at' => now()->subHours(3)]);    // daily

    $rows = $this->totals->perUser();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['handle'])->toBe('heavy')
        ->and($rows[0])->toMatchArray(['account_email' => 'team-a@example.com', 'hourly' => 20, 'daily' => 220, 'monthly' => 220])
        ->and($rows[1]['handle'])->toBe('light')
        ->and($rows[1]['account_email'])->toBeNull();
});
