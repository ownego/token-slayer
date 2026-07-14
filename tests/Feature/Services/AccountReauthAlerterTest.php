<?php

use App\Enums\AccountStatus;
use App\Models\Account;
use App\Services\AccountReauthAlerter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('it posts a reauth alert to the security webhook', function () {
    config(['services.slack_security.webhook_url' => 'https://hooks.slack.test/security']);
    Http::fake(['https://hooks.slack.test/*' => Http::response('', 200)]);

    $account = Account::factory()->needsReauth()->create([
        'email' => 'leaked@example.com',
    ]);

    $result = app(AccountReauthAlerter::class)->alert($account, 'invalid_grant');

    expect($result)->toBeTrue();
    Http::assertSent(function ($request) {
        $body = json_encode($request->data());

        return $request->url() === 'https://hooks.slack.test/security'
            && str_contains($body, 'leaked@example.com')
            && str_contains($body, 'invalid_grant');
    });
});

test('it skips when the security webhook is not configured', function () {
    config(['services.slack_security.webhook_url' => null]);
    Http::fake();

    $account = Account::factory()->needsReauth()->create();

    $result = app(AccountReauthAlerter::class)->alert($account, 'invalid_grant');

    expect($result)->toBeFalse();
    Http::assertNothingSent();
});

test('it returns false and does not throw when the post fails', function () {
    config(['services.slack_security.webhook_url' => 'https://hooks.slack.test/security']);
    Http::fake(fn () => throw new ConnectionException('down'));

    $account = Account::factory()->needsReauth()->create();

    $result = app(AccountReauthAlerter::class)->alert($account, 'unauthorized');

    expect($result)->toBeFalse();
});

test('it never includes token material in the alert payload', function () {
    config(['services.slack_security.webhook_url' => 'https://hooks.slack.test/security']);
    Http::fake(['https://hooks.slack.test/*' => Http::response('', 200)]);

    $account = Account::factory()->connected()->create([
        'status' => AccountStatus::NeedsReauth,
        'oauth_access_token' => 'sk-ant-oat01-SECRETACCESS',
        'oauth_refresh_token' => 'sk-ant-ort01-SECRETREFRESH',
    ]);

    app(AccountReauthAlerter::class)->alert($account, 'invalid_grant');

    Http::assertSent(function ($request) {
        $body = json_encode($request->data());

        return ! str_contains($body, 'SECRETACCESS')
            && ! str_contains($body, 'SECRETREFRESH')
            && ! str_contains($body, 'sk-ant-');
    });
});
