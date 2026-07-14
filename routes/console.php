<?php

use Illuminate\Console\Scheduling\Schedule as ScheduleClass;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('fighters:sweep-idle')->everyMinute();

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

Schedule::command('accounts:probe')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('accounts:prune-usage-snapshots')->daily();

Schedule::command('accounts:sync-profiles')
    ->daily()
    ->withoutOverlapping();
