<?php

use App\Support\SheetFormat;
use Carbon\CarbonImmutable;

test('a quota reading maps to the mockup\'s three states', function (int $percent, string $expected) {
    expect(SheetFormat::quotaStatus($percent))->toBe($expected);
})->with([
    'plenty of room' => [42, 'good'],
    'close at 70' => [70, 'warning'],
    'almost out at 95' => [95, 'critical'],
]);

test('the rank reads as an ordinal plus the period it covers', function (?int $rank, string $period, string $expected) {
    expect(SheetFormat::rankLabel($rank, $period))->toBe($expected);
})->with([
    'today' => [3, 'today', '3rd today'],
    'this hour' => [2, 'hour', '2nd this hour'],
    'eleventh is th, not st' => [11, 'week', '11th this week'],
    'twenty-first is st' => [21, 'month', '21st this month'],
    'all time' => [1, 'all', '1st all time'],
    'custom range' => [4, 'custom', '4th in range'],
    'no damage, no rank' => [null, 'today', ''],
]);

test('a refill countdown keeps its two largest units', function (int $seconds, string $expected) {
    $now = CarbonImmutable::parse('2026-09-27 12:00:00');

    expect(SheetFormat::duration($now->addSeconds($seconds), $now))->toBe($expected);
})->with([
    'minutes only' => [38 * 60, '38m'],
    'hours and minutes' => [2 * 3600 + 14 * 60, '2h 14m'],
    'days and hours' => [3 * 86400 + 10 * 3600, '3d 10h'],
    'under a minute' => [20, '<1m'],
]);

test('a past moment reads like the mockup\'s last-seen column', function (?int $secondsAgo, string $expected) {
    $now = CarbonImmutable::parse('2026-09-27 12:00:00');

    expect(SheetFormat::ago($secondsAgo === null ? null : $now->subSeconds($secondsAgo), $now))->toBe($expected);
})->with([
    'seconds' => [40, '40s ago'],
    'recent minutes keep seconds' => [125, '2m 5s ago'],
    'older minutes drop them' => [41 * 60, '41m ago'],
    'hours' => [3 * 3600 + 12 * 60, '3h 12m ago'],
    'days' => [3 * 86400 + 10 * 3600, '3d 10h ago'],
    'never seen' => [null, 'never'],
]);

test('a ledger share keeps one decimal below one percent', function (float $percent, string $expected) {
    expect(SheetFormat::percent($percent))->toBe($expected);
})->with([
    'tiny share' => [0.42, '0.4%'],
    'whole share' => [18.6, '19%'],
]);

test('the delta line compares against the previous stretch of the same period', function (?float $delta, string $period, string $expected) {
    expect(SheetFormat::deltaLabel($delta, $period))->toBe($expected);
})->with([
    'up on yesterday' => [12.4, 'today', '▲ 12% vs yesterday'],
    'down on last week' => [-4.0, 'week', '▼ 4% vs last week'],
    'hour' => [8.0, 'hour', '▲ 8% vs last hour'],
    'month' => [21.0, 'month', '▲ 21% vs last month'],
    'nothing to compare' => [null, 'today', ''],
]);

test('the ledger heading names the period', function () {
    expect(SheetFormat::periodName('week'))->toBe('this week')
        ->and(SheetFormat::periodName('all'))->toBe('all time');
});

test('the ledger flags a window that starts before input and cache were tracked', function (?string $start, bool $expected) {
    expect(SheetFormat::ledgerPartial($start === null ? null : CarbonImmutable::parse($start)))->toBe($expected);
})->with([
    'all time has no start' => [null, true],
    'a month reaching back before 11 Sep' => ['2026-09-01', true],
    'a week inside the tracked stretch' => ['2026-09-21', false],
]);
