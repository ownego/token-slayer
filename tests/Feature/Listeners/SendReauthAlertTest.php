<?php

use App\Events\AccountTokenRejected;
use App\Models\Account;
use App\Notifications\AccountTokenRejectedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.slack_security.bot_token' => 'xoxb-test-token',
        'services.slack_security.channel' => 'C0SECURITY',
    ]);
});

test('dispatching the event sends the reauth slack notification with the account and reason', function () {
    Notification::fake();
    $account = Account::factory()->needsReauth()->create();

    AccountTokenRejected::dispatch($account, 'invalid_grant');

    Notification::assertSentOnDemand(
        AccountTokenRejectedNotification::class,
        function (AccountTokenRejectedNotification $notification, array $channels) use ($account) {
            return $notification->account->is($account)
                && $notification->reason === 'invalid_grant'
                && in_array('slack', $channels, true);
        }
    );
});

test('it does not send when the security bot is not configured', function () {
    config(['services.slack_security.bot_token' => null]);
    Notification::fake();
    $account = Account::factory()->needsReauth()->create();

    AccountTokenRejected::dispatch($account, 'invalid_grant');

    Notification::assertNothingSent();
});
