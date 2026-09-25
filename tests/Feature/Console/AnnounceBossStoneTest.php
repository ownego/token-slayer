<?php

use App\Models\Boss;
use Carbon\CarbonImmutable;
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

test('announces the stone ThaNode just collected, with its name and running count', function () {
    Boss::factory()->thanode()->create(['spawned_at' => '2026-09-21T03:00:00Z']); // 2 mornings survived

    $this->artisan('boss:announce-stone')->assertSuccessful();

    expect(slackText())
        ->toContain('ThaNode')
        ->toContain('Reality Stone')
        ->toContain('3/6');
});

test('announces the complete gauntlet on the sixth stone', function () {
    Boss::factory()->thanode()->create(['spawned_at' => '2026-09-18T03:00:00Z']); // 5 mornings survived

    $this->artisan('boss:announce-stone')->assertSuccessful();

    expect(slackText())->toContain('Mind Stone')->toContain('6/6')->toContain('complete');
});

test('stays silent on every morning after the gauntlet is complete', function () {
    Boss::factory()->thanode()->create(['spawned_at' => '2026-09-18T03:00:00Z']);
    $this->artisan('boss:announce-stone')->assertSuccessful(); // 6/6 today

    foreach ([1, 2, 3] as $daysLater) {
        $this->travelTo(CarbonImmutable::parse(TICK)->addDays($daysLater));
        $this->artisan('boss:announce-stone')->assertSuccessful();
    }

    Http::assertSentCount(1);
});

test('does not announce the same stone twice', function () {
    Boss::factory()->thanode()->create(['spawned_at' => '2026-09-21T03:00:00Z']);

    $this->artisan('boss:announce-stone')->assertSuccessful();
    $this->artisan('boss:announce-stone')->assertSuccessful();

    Http::assertSentCount(1);
});

test('announces the opening stone if run before the first 09:30', function () {
    Boss::factory()->thanode()->create(['spawned_at' => '2026-09-23T03:00:00Z']); // after today's tick
    $this->travelTo('2026-09-23T05:00:00Z');

    $this->artisan('boss:announce-stone')->assertSuccessful();

    expect(slackText())->toContain('Power Stone')->toContain('1/6');
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
    Boss::factory()->thanode()->create(['spawned_at' => '2026-09-21T03:00:00Z']);

    $this->artisan('boss:announce-stone')->assertSuccessful();

    Http::assertNothingSent();
});

test('is scheduled at the stone clock instant in its timezone', function () {
    $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command ?? '', 'boss:announce-stone'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 9 * * *')
        ->and($event->timezone)->toBe('Asia/Ho_Chi_Minh');
});
