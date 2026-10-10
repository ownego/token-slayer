<?php

use App\Support\DaysLeft;
use Illuminate\Support\Carbon;

beforeEach(fn () => Carbon::setTestNow('2026-10-04 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('labels a deadline in whole days', function (?string $deadline, bool $estimated, string $expected): void {
    expect(DaysLeft::label($deadline === null ? null : Carbon::parse($deadline), $estimated))->toBe($expected);
})->with([
    'no deadline' => [null, false, '—'],
    'later today' => ['2026-10-04 20:00:00', false, 'today'],
    'earlier today' => ['2026-10-04 01:00:00', false, 'today'],
    'five and a half days left' => ['2026-10-10 00:00:00', false, '5d left'],
    'two days overdue' => ['2026-10-02 06:00:00', false, '2d overdue'],
    'estimated' => ['2026-10-16 12:00:00', true, '~12d left'],
]);

it('flags only past deadlines as overdue', function (): void {
    expect(DaysLeft::isOverdue(Carbon::parse('2026-10-04 11:00:00')))->toBeTrue()
        ->and(DaysLeft::isOverdue(Carbon::parse('2026-10-05')))->toBeFalse()
        ->and(DaysLeft::isOverdue(null))->toBeFalse();
});
