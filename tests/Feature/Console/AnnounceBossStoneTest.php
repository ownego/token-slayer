<?php

use App\Models\Boss;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// 09:30 Asia/Ho_Chi_Minh on 2026-09-23 — the moment the schedule fires.
const TICK = '2026-09-23T02:30:00Z';

beforeEach(function () {
    config(['services.slack_notifier.webhook_url' => 'https://hooks.slack.example/T/B/X']);
    Cache::flush(); // the array store outlives RefreshDatabase's transaction
    Http::fake();
    $this->travelTo(TICK);
});

function slackText(): string
{
    $text = '';
    Http::assertSent(function ($request) use (&$text) {
        $text = json_encode($request->data(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return true;
    });

    return $text;
}

test('announces the stone ThaNode just collected, with its name, running count and next tick', function () {
    Boss::factory()->thanode()->create(['spawned_at' => '2026-09-22T03:00:00Z']); // 14:00, 17:50, then this 09:30

    $this->artisan('boss:announce-stone')->assertSuccessful();

    expect(slackText())
        ->toContain('ThaNode')
        ->toContain('Soul Stone')
        ->toContain('4/6')
        ->toContain('next one lands at 14:00 today');
});

test('after the last tick of the day the next one is tomorrow morning', function () {
    Boss::factory()->thanode()->create(['spawned_at' => '2026-09-23T03:00:00Z']);
    $this->travelTo('2026-09-23T10:50:00Z'); // 17:50

    $this->artisan('boss:announce-stone')->assertSuccessful();

    expect(slackText())->toContain('Reality Stone')->toContain('3/6')->toContain('next one lands at 09:30 tomorrow');
});

test('announces the complete gauntlet on the sixth stone', function () {
    Boss::factory()->thanode()->create(['spawned_at' => '2026-09-21T08:00:00Z']); // fifth tick after spawn is TICK

    $this->artisan('boss:announce-stone')->assertSuccessful();

    expect(slackText())->toContain('Mind Stone')->toContain('6/6')->toContain('complete');
});

test('stays silent on every tick after the gauntlet is complete', function () {
    Boss::factory()->thanode()->create(['spawned_at' => '2026-09-21T08:00:00Z']);
    $this->artisan('boss:announce-stone')->assertSuccessful(); // 6/6 at TICK

    foreach (['2026-09-23T07:00:00Z', '2026-09-23T10:50:00Z', '2026-09-24T02:30:00Z', '2026-09-26T02:30:00Z'] as $later) {
        $this->travelTo($later);
        $this->artisan('boss:announce-stone')->assertSuccessful();
    }

    Http::assertSentCount(1);
});

test('does not announce the same stone twice', function () {
    Boss::factory()->thanode()->create(['spawned_at' => '2026-09-22T03:00:00Z']);

    $this->artisan('boss:announce-stone')->assertSuccessful();
    $this->artisan('boss:announce-stone')->assertSuccessful();

    Http::assertSentCount(1);
});

test('never announces the opening stone, which the kill line already carries', function () {
    Boss::factory()->thanode()->create(['spawned_at' => '2026-09-23T02:30:05Z']); // spawned inside the 09:30 minute
    $this->travelTo('2026-09-23T02:30:30Z'); // the cron run a few seconds later

    $this->artisan('boss:announce-stone')->assertSuccessful();

    Http::assertNothingSent();
});

test('stays silent when run long after the last tick', function () {
    Boss::factory()->thanode()->create(['spawned_at' => '2026-09-22T03:00:00Z']);
    $this->travelTo('2026-09-23T05:00:00Z'); // 12:00 local, 09:30's stone is old news

    $this->artisan('boss:announce-stone')->assertSuccessful();

    Http::assertNothingSent();
});

test('a cache flush after the gauntlet is complete does not re-announce it', function () {
    Boss::factory()->thanode()->create(['spawned_at' => '2026-09-21T08:00:00Z']);
    $this->artisan('boss:announce-stone')->assertSuccessful(); // 6/6 at TICK

    Cache::flush();
    $this->travelTo('2026-09-23T07:00:00Z');
    $this->artisan('boss:announce-stone')->assertSuccessful();

    Http::assertSentCount(1);
});

test('a scheduler run a few minutes late still announces the stone', function () {
    Boss::factory()->thanode()->create(['spawned_at' => '2026-09-22T03:00:00Z']);
    $this->travelTo('2026-09-23T02:40:00Z'); // ten minutes after 09:30

    $this->artisan('boss:announce-stone')->assertSuccessful();

    expect(slackText())->toContain('Soul Stone')->toContain('4/6');
});

test('stays silent for a generic monster', function () {
    Boss::factory()->create(['number' => 1, 'spawned_at' => '2026-09-16T03:00:00Z']);

    $this->artisan('boss:announce-stone')->assertSuccessful();

    Http::assertNothingSent();
});

test('stays silent for a pool-named boss that happens to hold the old thanos slot number', function () {
    Boss::factory()->create(['number' => 55, 'name' => 'Smaug', 'spawned_at' => '2026-09-16T03:00:00Z']);

    $this->artisan('boss:announce-stone')->assertSuccessful();

    Http::assertNothingSent();
});

test('stays silent when no boss is alive, without spawning one', function () {
    Boss::factory()->defeated()->thanode()->create();

    $this->artisan('boss:announce-stone')->assertSuccessful();

    Http::assertNothingSent();
    expect(Boss::where('status', 'alive')->exists())->toBeFalse();
});

test('missing webhook URL skips posting without failing', function () {
    config(['services.slack_notifier.webhook_url' => null]);
    Boss::factory()->thanode()->create(['spawned_at' => '2026-09-22T03:00:00Z']);

    $this->artisan('boss:announce-stone')->assertSuccessful();

    Http::assertNothingSent();
});

test('is scheduled at every stone clock tick in its timezone', function () {
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->filter(fn ($e) => str_contains($e->command ?? '', 'boss:announce-stone'));

    expect($events->pluck('expression')->sort()->values()->all())->toBe(['0 14 * * *', '30 9 * * *', '50 17 * * *'])
        ->and($events->pluck('timezone')->unique()->all())->toBe(['Asia/Ho_Chi_Minh']);
});
