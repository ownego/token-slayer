<?php

use App\Models\Event;
use App\Models\User;
use App\Services\Analytics\UsageFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Freeze "now" far enough past the fixture event below that the
    // all-time span always exceeds the ~2-year weekly-bucket ceiling,
    // regardless of the real wall-clock date the suite runs on.
    Carbon::setTestNow(Carbon::parse('2027-06-01 00:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

test('it derives an all-time range from the earliest event with a coarse bucket', function () {
    $user = User::factory()->create();
    Event::factory()->for($user)->create(['created_at' => Carbon::parse('2025-01-01 00:00:00')]);

    $f = UsageFilters::fromPageFilters(['range' => 'all']);

    expect($f->from->toDateString())->toBe('2025-01-01')
        ->and($f->bucket)->toBe('month');
});

test('it falls back to a far-past all-time range when there are no events', function () {
    $f = UsageFilters::fromPageFilters(['range' => 'all']);

    expect($f->from->lessThanOrEqualTo(now()->subYears(4)))->toBeTrue()
        ->and($f->bucket)->toBe('month');
});

test('it derives a this-today range starting at the start of today', function () {
    $f = UsageFilters::fromPageFilters(['range' => 'today']);

    expect($f->from->toDateString())->toBe(now()->toDateString())
        ->and($f->from->format('H:i:s'))->toBe('00:00:00');
});

test('it derives a this-week range starting at the start of the week', function () {
    $f = UsageFilters::fromPageFilters(['range' => 'week']);

    expect($f->from->toDateString())->toBe(now()->startOfWeek()->toDateString())
        ->and($f->from->lessThanOrEqualTo(now()))->toBeTrue();
});

test('it derives a this-month range starting at the first of the month', function () {
    $f = UsageFilters::fromPageFilters(['range' => 'month']);

    expect($f->from->day)->toBe(1)
        ->and($f->from->toDateString())->toBe(now()->startOfMonth()->toDateString());
});

test('it derives a this-year range starting at the start of the year', function () {
    $f = UsageFilters::fromPageFilters(['range' => 'year']);

    expect($f->from->toDateString())->toBe(now()->startOfYear()->toDateString());
});

test('it does not clamp the all-time range to the ninety day max', function () {
    $user = User::factory()->create();
    Event::factory()->for($user)->create(['created_at' => now()->subYears(3)]);

    $f = UsageFilters::fromPageFilters(['range' => 'all']);

    expect($f->from->lessThanOrEqualTo(now()->subYears(2)))->toBeTrue();
});

test('it does not clamp the year range to the ninety day max', function () {
    $f = UsageFilters::fromPageFilters(['range' => 'year']);

    expect($f->from->toDateString())->toBe(now()->startOfYear()->toDateString());
});

test('a range spanning between ninety days and roughly two years buckets weekly', function () {
    $f = new UsageFilters(now()->subDays(200), now(), null, null, null);

    expect($f->bucket)->toBe('week');
});

test('a range longer than roughly two years buckets monthly', function () {
    $f = new UsageFilters(now()->subDays(800), now(), null, null, null);

    expect($f->bucket)->toBe('month');
});
