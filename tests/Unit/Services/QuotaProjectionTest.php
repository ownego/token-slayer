<?php

use App\Services\QuotaProjection;
use Illuminate\Support\Carbon;

test('it extrapolates the current burn rate to the reset boundary', function () {
    // Window is 10h long, 2h elapsed, at 20% → rate 10%/h → +8h = +80% → 100 (clamped).
    $windowStart = Carbon::parse('2026-07-11 00:00:00');
    $now = Carbon::parse('2026-07-11 02:00:00');
    $resetAt = Carbon::parse('2026-07-11 10:00:00');

    expect(QuotaProjection::projectedAtReset(20, $windowStart, $resetAt, $now))->toBe(100);
});

test('it projects a mid-range value without clamping', function () {
    // 10h window, 5h elapsed at 20% → 4%/h → remaining 5h → +20% → 40.
    $windowStart = Carbon::parse('2026-07-11 00:00:00');
    $now = Carbon::parse('2026-07-11 05:00:00');
    $resetAt = Carbon::parse('2026-07-11 10:00:00');

    expect(QuotaProjection::projectedAtReset(20, $windowStart, $resetAt, $now))->toBe(40);
});

test('it returns the current value when the window has not advanced', function () {
    $t = Carbon::parse('2026-07-11 00:00:00');

    expect(QuotaProjection::projectedAtReset(37, $t, $t->copy()->addHours(5), $t))->toBe(37);
});

test('it never returns below zero or above one hundred', function () {
    $windowStart = Carbon::parse('2026-07-11 00:00:00');
    $now = Carbon::parse('2026-07-11 01:00:00');
    $resetAt = Carbon::parse('2026-07-11 10:00:00');

    expect(QuotaProjection::projectedAtReset(0, $windowStart, $resetAt, $now))->toBe(0)
        ->and(QuotaProjection::projectedAtReset(95, $windowStart, $resetAt, $now))->toBe(100);
});

test('projectedAtResetFromDailyRate projects past 100 without clamping', function () {
    $now = Carbon::parse('2026-09-12 00:00:00');
    $resetAt = $now->copy()->addDays(4);

    // 20% now, burning 30%/day for 4 more days => 20 + 120 = 140.
    expect(QuotaProjection::projectedAtResetFromDailyRate(20, 30.0, $resetAt, $now))->toBe(140);
});

test('projectedAtResetFromDailyRate returns the current value unchanged once the reset has passed', function () {
    $now = Carbon::parse('2026-09-12 00:00:00');
    $resetAt = $now->copy()->subHour();

    expect(QuotaProjection::projectedAtResetFromDailyRate(55, 10.0, $resetAt, $now))->toBe(55);
});
