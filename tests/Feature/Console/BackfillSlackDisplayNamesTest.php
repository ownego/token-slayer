<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('backfill updates slack_handle for linked users via the bot token', function () {
    config(['services.slack.bot_token' => 'xoxb-test-token']);

    Http::fake([
        'slack.com/api/users.info*' => Http::response([
            'ok' => true,
            'user' => ['profile' => ['display_name' => 'sonnh', 'real_name' => 'Nguyễn Hoàng Sơn']],
        ]),
    ]);

    $user = User::factory()->create(['slack_user_id' => 'U123', 'display_name' => 'Stale Name']);

    $this->artisan('slack:backfill-display-names')->assertSuccessful();

    expect($user->refresh()->display_name)->toBe('sonnh');

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer xoxb-test-token'));
});

test('backfill fails fast when the bot token is missing', function () {
    config(['services.slack.bot_token' => null]);
    Http::fake();

    $this->artisan('slack:backfill-display-names')->assertFailed();

    Http::assertNothingSent();
});
