<?php

use App\Models\Account;
use App\Services\AccountConnectService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('persists refresh_token_expires_in from the token response into oauth_refresh_expires_at', function () {
    $account = Account::factory()->create(['oauth_refresh_expires_at' => now()->subDay()]); // stale/past, like a Reconnect target

    $service = app(AccountConnectService::class);
    $reflection = new ReflectionMethod($service, 'writeGrant');
    $reflection->setAccessible(true);
    $reflection->invoke($service, $account, 'sk-ant-oat01-fake', 'sk-ant-ort01-fake', 28800, 2_000_000);

    expect($account->oauth_refresh_expires_at)->not->toBeNull()
        ->and($account->oauth_refresh_expires_at->isAfter(now()->addDays(20)))->toBeTrue();
});

it('leaves oauth_refresh_expires_at unchanged when the token response has no refresh_token_expires_in', function () {
    $original = now()->addDays(10);
    $account = Account::factory()->create(['oauth_refresh_expires_at' => $original]);

    $service = app(AccountConnectService::class);
    $reflection = new ReflectionMethod($service, 'writeGrant');
    $reflection->setAccessible(true);
    $reflection->invoke($service, $account, 'sk-ant-oat01-fake', 'sk-ant-ort01-fake', 28800, null);

    expect($account->oauth_refresh_expires_at->timestamp)->toBe($original->timestamp);
});
