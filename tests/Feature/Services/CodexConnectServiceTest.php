<?php

use App\Enums\AccountStatus;
use App\Enums\Provider;
use App\Exceptions\CodexConnectException;
use App\Models\Account;
use App\Models\CodexCredential;
use App\Services\CodexConnectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// Same fixture shape as CodexProvisioningServiceTest's fakeCodexAuthJson().
const DEVICE_ID_TOKEN_PAYLOAD_B64 = 'eyJlbWFpbCI6ICJzaGFyZWRAZXhhbXBsZS5jb20iLCAiaHR0cHM6Ly9hcGkub3BlbmFpLmNvbS9hdXRoIjogeyJjaGF0Z3B0X2FjY291bnRfaWQiOiAiYWNjdC0xIn19';

it('start() returns a state, user_code, and the device verification URL', function (): void {
    Http::fake([
        'auth.openai.com/api/accounts/deviceauth/usercode' => Http::response([
            'device_auth_id' => 'da-1',
            'user_code' => 'ABCD-1234',
            'interval' => 5,
            'expires_at' => now()->addMinutes(15)->toIso8601String(),
        ], 200),
    ]);

    $result = app(CodexConnectService::class)->start();

    expect($result['user_code'])->toBe('ABCD-1234')
        ->and($result['verification_url'])->toBe('https://auth.openai.com/codex/device')
        ->and($result['state'])->not->toBeEmpty();
});

it('start() throws a friendly CodexConnectException when device-code login is disabled for the account (a 404)', function (): void {
    Http::fake([
        'auth.openai.com/api/accounts/deviceauth/usercode' => Http::response([
            'error' => 'device code login is not enabled for this Codex server. Use the browser login or verify the server URL.',
        ], 404),
    ]);

    try {
        app(CodexConnectService::class)->start();
        test()->fail('expected a CodexConnectException');
    } catch (CodexConnectException $exception) {
        expect($exception->reason)->toBe('codex_connect_device_code_disabled')
            ->and($exception->getMessage())->toContain('a workspace admin must turn it on');
    }
});

it('poll() returns pending while the device-auth token endpoint reports pending', function (): void {
    Http::fake([
        'auth.openai.com/api/accounts/deviceauth/usercode' => Http::response([
            'device_auth_id' => 'da-1', 'user_code' => 'ABCD-1234', 'interval' => 5,
            'expires_at' => now()->addMinutes(15)->toIso8601String(),
        ], 200),
        'auth.openai.com/api/accounts/deviceauth/token' => Http::response([
            'error' => ['code' => 'deviceauth_authorization_pending'],
        ], 400),
    ]);
    $started = app(CodexConnectService::class)->start();

    $result = app(CodexConnectService::class)->poll($started['state'], 'Company ChatGPT');

    expect($result->status)->toBe('pending');
});

it('poll() connects the account and returns done once the token endpoint reports success', function (): void {
    Http::fake([
        'auth.openai.com/api/accounts/deviceauth/usercode' => Http::response([
            'device_auth_id' => 'da-1', 'user_code' => 'ABCD-1234', 'interval' => 5,
            'expires_at' => now()->addMinutes(15)->toIso8601String(),
        ], 200),
        'auth.openai.com/api/accounts/deviceauth/token' => Http::response([
            'status' => 'success', 'authorization_code' => 'code-1',
            'code_challenge' => 'chal-1', 'code_verifier' => 'verifier-1',
        ], 200),
        'auth.openai.com/oauth/token' => Http::response([
            'access_token' => 'h.'.DEVICE_ID_TOKEN_PAYLOAD_B64.'.s',
            'id_token' => 'h.'.DEVICE_ID_TOKEN_PAYLOAD_B64.'.s',
            'refresh_token' => 'refresh-1',
            'expires_in' => 864000,
            'earliest_refresh_at' => now()->addDays(9)->timestamp,
        ], 200),
    ]);
    $started = app(CodexConnectService::class)->start();

    $result = app(CodexConnectService::class)->poll($started['state'], 'Company ChatGPT');

    expect($result->status)->toBe('done')
        ->and($result->account->provider)->toBe(Provider::Codex)
        ->and($result->account->email)->toBe('shared@example.com')
        ->and($result->account->codexCredential->earliest_refresh_at)->not->toBeNull();
});

it('poll() returns expired once the cached state has been forgotten (past expires_at)', function (): void {
    $result = app(CodexConnectService::class)->poll('a-state-nobody-cached', 'Company ChatGPT');

    expect($result->status)->toBe('expired');
});

it('disconnect() revokes and wipes the stored Codex tokens', function (): void {
    Http::fake(['auth.openai.com/oauth/revoke' => Http::response([], 200)]);
    $account = Account::factory()->create(['provider' => 'codex']);
    CodexCredential::factory()->for($account)->create([
        'codex_access_token' => 'access-1',
        'codex_refresh_token' => 'refresh-1',
    ]);

    app(CodexConnectService::class)->disconnect($account->fresh());

    Http::assertSent(fn ($request) => ($request['token'] ?? null) === 'refresh-1');
    $account->refresh();
    expect($account->codexCredential->codex_access_token)->toBeNull()
        ->and($account->status)->toBe(AccountStatus::NeedsReauth);
});
