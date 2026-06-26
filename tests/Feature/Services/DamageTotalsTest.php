<?php

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
    ]);
});

test('forUser scopes totals to one user', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    Event::factory()->create(['user_id' => $alice->id, 'boss_id' => $this->boss->id, 'tokens' => 100, 'created_at' => now()->subHour()]);
    Event::factory()->create(['user_id' => $bob->id, 'boss_id' => $this->boss->id, 'tokens' => 999, 'created_at' => now()->subHour()]);

    expect($this->totals->forUser($alice))->toBe([
        'allTime' => 100,
        'monthly' => 100,
        'daily' => 100,
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
    expect($this->totals->global())->toBe(['allTime' => 0, 'monthly' => 0, 'daily' => 0]);
});

test('forUser returns zero totals when the user has no events', function () {
    $user = User::factory()->create();

    expect($this->totals->forUser($user))->toBe(['allTime' => 0, 'monthly' => 0, 'daily' => 0]);
});
