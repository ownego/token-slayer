<?php

use App\Models\Event;
use App\Models\User;
use App\Services\Profile\Period;
use App\Services\Profile\PeriodStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-27 05:00:00'); // 12:00 local
});

test('rank is the fighter\'s place by damage in the window, among fighters who hit at all', function () {
    [$me, $top, $low] = User::factory()->count(3)->create();
    Event::factory()->for($top)->create(['tokens' => 500, 'created_at' => now()->subMinutes(5)]);
    Event::factory()->for($me)->create(['tokens' => 300, 'created_at' => now()->subMinutes(5)]);
    Event::factory()->for($low)->create(['tokens' => 100, 'created_at' => now()->subMinutes(5)]);

    expect(app(PeriodStats::class)->for($me, Period::Today)['rank'])->toBe(2);
});

test('a fighter with no damage in the window has no rank, not last place', function () {
    $me = User::factory()->create();
    Event::factory()->for(User::factory()->create())->create(['tokens' => 50, 'created_at' => now()->subMinutes(5)]);

    expect(app(PeriodStats::class)->for($me, Period::Today)['rank'])->toBeNull();
});

test('tokens break the window down into output, input, cache written and cache read', function () {
    $me = User::factory()->create();
    Event::factory()->for($me)->create([
        'tokens' => 100, 'input_tokens' => 20, 'cache_creation_input_tokens' => 300, 'cache_read_input_tokens' => 4000,
        'created_at' => now()->subMinutes(5),
    ]);
    // yesterday: outside "today"
    Event::factory()->for($me)->create(['tokens' => 999, 'created_at' => now()->subDay()]);

    expect(app(PeriodStats::class)->for($me, Period::Today)['tokens'])
        ->toBe(['out' => 100, 'in' => 20, 'cw' => 300, 'cr' => 4000]);
});

test('delta compares the window so far with the same stretch of the previous one', function () {
    $me = User::factory()->create();
    // today 00:00-12:00 local: 120
    Event::factory()->for($me)->create(['tokens' => 120, 'created_at' => now()->subHours(2)]);
    // yesterday 00:00-12:00 local: 100 (counts)
    Event::factory()->for($me)->create(['tokens' => 100, 'created_at' => now()->subDay()->subHours(2)]);
    // yesterday afternoon: after the same stretch, so it doesn't count
    Event::factory()->for($me)->create(['tokens' => 900, 'created_at' => now()->subDay()->addHours(3)]);

    expect(app(PeriodStats::class)->for($me, Period::Today)['delta'])->toBe(20.0);
});

test('delta is null with nothing to compare against, and for all-time/year', function () {
    $me = User::factory()->create();
    Event::factory()->for($me)->create(['tokens' => 120, 'created_at' => now()->subHours(2)]);

    expect(app(PeriodStats::class)->for($me, Period::Today)['delta'])->toBeNull()
        ->and(app(PeriodStats::class)->for($me, Period::All)['delta'])->toBeNull();
});
