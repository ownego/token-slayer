<?php

use App\Models\Event;
use App\Models\User;
use App\Services\Profile\DamageByPeriod;
use App\Services\Profile\Period;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('today starts at local midnight in the display timezone, not 24 hours ago', function () {
    // 2026-09-27 00:30 local (UTC+7) = 2026-09-26 17:30 UTC
    Carbon::setTestNow('2026-09-27 01:00:00');   // 08:00 local
    $me = User::factory()->create();
    Event::factory()->for($me)->create(['tokens' => 100, 'created_at' => '2026-09-26 17:30:00']);  // 00:30 local today
    Event::factory()->for($me)->create(['tokens' => 50, 'created_at' => '2026-09-26 16:30:00']);   // 23:30 local yesterday

    expect(app(DamageByPeriod::class)->for($me, Period::Today)['mine'])->toBe(100);
});

test('share is mine over the whole team for the same window, and zero when nobody hit', function () {
    Carbon::setTestNow('2026-09-27 05:00:00');
    $me = User::factory()->create();
    $mate = User::factory()->create();
    Event::factory()->for($me)->create(['tokens' => 30, 'created_at' => now()->subMinutes(10)]);
    Event::factory()->for($mate)->create(['tokens' => 70, 'created_at' => now()->subMinutes(10)]);

    expect(app(DamageByPeriod::class)->for($me, Period::Hour))->toBe(['mine' => 30, 'team' => 100, 'share' => 0.3]);
});

test('a fighter with no hits gets a 0.0 share, never NaN, even when the team has damage', function () {
    Event::factory()->create(['user_id' => User::factory()->create()->id, 'tokens' => 50]);

    expect(app(DamageByPeriod::class)->for(User::factory()->create(), Period::All))->toBe(['mine' => 0, 'team' => 50, 'share' => 0.0]);
});

test('an empty table gives zeros for everyone', function () {
    expect(app(DamageByPeriod::class)->for(User::factory()->create(), Period::Year))->toBe(['mine' => 0, 'team' => 0, 'share' => 0.0]);
});

test('a custom range is inclusive of both days in the display timezone', function () {
    $me = User::factory()->create();
    Event::factory()->for($me)->create(['tokens' => 10, 'created_at' => '2026-09-14 17:00:00']);  // 00:00 local 15th
    Event::factory()->for($me)->create(['tokens' => 20, 'created_at' => '2026-09-27 16:59:00']);  // 23:59 local 27th

    $range = app(DamageByPeriod::class)->between($me,
        Carbon::parse('2026-09-15', 'Asia/Ho_Chi_Minh')->toImmutable(), Carbon::parse('2026-09-27', 'Asia/Ho_Chi_Minh')->toImmutable());

    expect($range['mine'])->toBe(30);
});
