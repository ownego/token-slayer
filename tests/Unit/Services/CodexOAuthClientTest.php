<?php

use App\Services\CodexOAuthClient;
use Illuminate\Support\Facades\Http;

it('requestUserCode posts the client_id and returns the decoded device-auth response', function (): void {
    Http::fake([
        'auth.openai.com/api/accounts/deviceauth/usercode' => Http::response([
            'device_auth_id' => 'da-1',
            'user_code' => 'ABCD-1234',
            'interval' => 5,
            'expires_at' => now()->addMinutes(15)->toIso8601String(),
        ], 200),
    ]);

    $result = app(CodexOAuthClient::class)->requestUserCode();

    expect($result['device_auth_id'])->toBe('da-1')
        ->and($result['user_code'])->toBe('ABCD-1234');
    Http::assertSent(fn ($request) => $request['client_id'] === 'app_EMoamEEZ73f0CkXaXp7hrann');
});

it('requestUserCode sends a JSON body, not form-encoded', function (): void {
    // Verified live against the real endpoint: form-encoding gets a 400
    // ("Input should be a valid dictionary or object to extract fields
    // from" -- a Pydantic error, meaning the body itself failed to parse as
    // JSON, not that a field was missing). `Http::fake()`/`assertSent`'s
    // array access on `$request['client_id']` works identically for a form
    // or a JSON body, so the wrong encoding shipped for a while with every
    // test here green -- this is the assertion that would have caught it.
    Http::fake([
        'auth.openai.com/api/accounts/deviceauth/usercode' => Http::response([
            'device_auth_id' => 'da-1', 'user_code' => 'ABCD-1234',
        ], 200),
    ]);

    app(CodexOAuthClient::class)->requestUserCode();

    Http::assertSent(fn ($request) => $request->isJson());
});

it('pollDeviceToken returns the pending status while the human has not yet approved', function (): void {
    Http::fake([
        'auth.openai.com/api/accounts/deviceauth/token' => Http::response([
            'error' => ['code' => 'deviceauth_authorization_pending'],
        ], 400),
    ]);

    $result = app(CodexOAuthClient::class)->pollDeviceToken('da-1', 'ABCD-1234');

    expect($result['status'])->toBe('deviceauth_authorization_pending');
});

it('pollDeviceToken returns the authorization_code payload once approved', function (): void {
    Http::fake([
        'auth.openai.com/api/accounts/deviceauth/token' => Http::response([
            'status' => 'success',
            'authorization_code' => 'code-1',
            'code_challenge' => 'chal-1',
            'code_verifier' => 'verifier-1',
        ], 200),
    ]);

    $result = app(CodexOAuthClient::class)->pollDeviceToken('da-1', 'ABCD-1234');

    expect($result['status'])->toBe('success')
        ->and($result['authorization_code'])->toBe('code-1')
        ->and($result['code_verifier'])->toBe('verifier-1');
});

it('pollDeviceToken sends a JSON body, not form-encoded', function (): void {
    // Same real-endpoint verification as requestUserCode's -- this custom
    // OpenAI deviceauth path rejects a form-encoded body outright with a
    // Pydantic "not a valid dictionary" 400, distinct from a real
    // authorization_pending/deviceauth_not_found response.
    Http::fake([
        'auth.openai.com/api/accounts/deviceauth/token' => Http::response([
            'status' => 'success', 'authorization_code' => 'code-1',
            'code_challenge' => 'chal-1', 'code_verifier' => 'verifier-1',
        ], 200),
    ]);

    app(CodexOAuthClient::class)->pollDeviceToken('da-1', 'ABCD-1234');

    Http::assertSent(fn ($request) => $request->isJson());
});

it('exchangeAuthorizationCode posts the fixed device-code redirect_uri and returns the token response', function (): void {
    Http::fake([
        'auth.openai.com/oauth/token' => Http::response([
            'access_token' => 'h.eyJleHAiOjF9.s',
            'id_token' => 'h.eyJlbWFpbCI6ICJ4QGV4YW1wbGUuY29tIn0.s',
            'refresh_token' => 'refresh-1',
            'expires_in' => 864000,
            'earliest_refresh_at' => now()->addDays(9)->timestamp,
        ], 200),
    ]);

    $result = app(CodexOAuthClient::class)->exchangeAuthorizationCode('code-1', 'verifier-1');

    expect($result['access_token'])->toBe('h.eyJleHAiOjF9.s');
    Http::assertSent(fn ($request) => $request['redirect_uri'] === 'https://auth.openai.com/deviceauth/callback'
        && $request['code_verifier'] === 'verifier-1');
});
