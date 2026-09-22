<?php

use Illuminate\Console\Scheduling\Schedule as ScheduleClass;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('fighters:sweep-idle')->everyMinute();

// Keeps the install-script/wheel digests warm so POST /api/events never
// resolves a GitHub release inline: that call has an 8s timeout while the
// client's curl gives up after 3s.
Schedule::command('client-artifacts:refresh')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('battlefield:recap daily')
    ->dailyAt('09:00')
    ->timezone('Asia/Ho_Chi_Minh');

Schedule::command('battlefield:recap weekly')
    ->weeklyOn(ScheduleClass::MONDAY, '09:00')
    ->timezone('Asia/Ho_Chi_Minh');

Schedule::command('battlefield:recap monthly')
    ->monthlyOn(1, '09:00')
    ->timezone('Asia/Ho_Chi_Minh');

Schedule::command('battlefield:recap yearly')
    ->yearlyOn(1, 1, '09:00')
    ->timezone('Asia/Ho_Chi_Minh');

// Fires at the stone-clock instant (09:30 Asia/Ho_Chi_Minh by default) so the
// Slack line lands the moment the gauntlet gains a stone; the time is read from
// the same config StoneClock uses, so the two can never drift apart.
Schedule::command('boss:announce-stone')
    ->dailyAt(sprintf('%02d:%02d', config('game.stones.hour'), config('game.stones.minute')))
    ->timezone(config('game.stones.timezone'))
    ->withoutOverlapping();

Schedule::command('accounts:probe')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Anchor each account's rolling 5h Anthropic window at fixed clock times
// (03:45 and 08:45 UTC+7) with a one-token message. No retry/catch-up — a late
// run would start the window off-schedule.
Schedule::command('accounts:anchor-sessions')
    ->twiceDailyAt(3, 8, 45)
    ->timezone('Asia/Ho_Chi_Minh')
    ->withoutOverlapping();

Schedule::command('accounts:sync-profiles')
    ->daily()
    ->withoutOverlapping();
