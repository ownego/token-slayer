<?php

use App\Events\BossKilled;
use App\Models\Boss;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('boss kill posts to Slack webhook', function () {
    config(['game.slack_kill_webhook_url' => 'https://hooks.slack/test']);
    Http::fake();

    $user = User::factory()->create(['slack_handle' => 'alice']);
    $boss = Boss::factory()->defeated()->create(['number' => 12, 'killing_blow_user_id' => $user->id]);

    event(new BossKilled($boss, $user));

    Http::assertSent(fn ($r) => $r->url() === 'https://hooks.slack/test'
        && str_contains($r['text'], 'Boss #12')
        && str_contains($r['text'], 'alice')
    );
});

test('boss kill listener is a no-op when webhook URL is not configured', function () {
    config(['game.slack_kill_webhook_url' => null]);
    Http::fake();

    $user = User::factory()->create(['slack_handle' => 'bob']);
    $boss = Boss::factory()->defeated()->create(['number' => 5, 'killing_blow_user_id' => $user->id]);

    event(new BossKilled($boss, $user));

    Http::assertNothingSent();
});
