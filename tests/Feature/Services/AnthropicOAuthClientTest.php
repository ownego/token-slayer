<?php

use App\Exceptions\UsageProbeException;
use App\Services\AnthropicOAuthClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->client = new AnthropicOAuthClient;
});

test('exchangeCode parses the authorization_code grant response', function () {
    fakeAnthropic();

    $token = $this->client->exchangeCode('a-code', 'a-verifier', 'a-state');

    expect($token['access_token'])->toBe('sk-ant-oat01-REDACTED')
        ->and($token['refresh_token'])->toBe('sk-ant-ort01-REDACTED')
        ->and($token['account']['email_address'])->toBe('ongtung2212002@gmail.com')
        ->and($token['organization']['uuid'])->toBe('7f993a12-f480-45cd-8b99-1e3182d168bf');

    Http::assertSent(function (Request $request) {
        return $request->url() === config('token_slayer.anthropic.token_endpoint')
            && $request->method() === 'POST'
            && $request->hasHeader('Content-Type', 'application/json')
            && $request['grant_type'] === 'authorization_code'
            && $request['code'] === 'a-code'
            && $request['code_verifier'] === 'a-verifier'
            && $request['state'] === 'a-state'
            && $request['client_id'] === config('token_slayer.anthropic.oauth_client_id')
            && $request['redirect_uri'] === config('token_slayer.anthropic.redirect_uri');
    });
});

test('refresh parses the rotated refresh_token grant response', function () {
    fakeAnthropic(['token' => Http::response(
        file_get_contents(base_path('tests/fixtures/anthropic/refresh.json')),
        200,
        ['Content-Type' => 'application/json']
    )]);

    $token = $this->client->refresh('an-old-refresh-token');

    expect($token['access_token'])->toBe('sk-ant-oat01-REDACTED')
        ->and($token['token_uuid'])->toBe('66d4280a-1be7-4e48-bdc5-b72eedbe2f52');

    Http::assertSent(function (Request $request) {
        return $request->url() === config('token_slayer.anthropic.token_endpoint')
            && $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'an-old-refresh-token'
            && $request['client_id'] === config('token_slayer.anthropic.oauth_client_id')
            && $request['scope'] === 'user:profile user:inference user:sessions:claude_code user:mcp_servers user:file_upload';
    });
});

test('usage parses the usage response and carries the required headers', function () {
    fakeAnthropic();

    $usage = $this->client->usage('an-access-token');

    expect($usage['five_hour']['utilization'])->toBe(0.0)
        ->and($usage['seven_day']['utilization'])->toBe(25.0);

    Http::assertSent(function (Request $request) {
        return $request->url() === config('token_slayer.anthropic.usage_endpoint')
            && $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer an-access-token')
            && $request->hasHeader('anthropic-beta', config('token_slayer.anthropic.beta_header'))
            && $request->hasHeader('User-Agent', config('token_slayer.anthropic.user_agent'));
    });
});

test('profile parses the profile response', function () {
    fakeAnthropic();

    $profile = $this->client->profile('an-access-token');

    expect($profile['account']['email'])->toBe('ongtung2212002@gmail.com')
        ->and($profile['organization']['uuid'])->toBe('7f993a12-f480-45cd-8b99-1e3182d168bf');

    Http::assertSent(function (Request $request) {
        return $request->url() === config('token_slayer.anthropic.profile_endpoint')
            && $request->hasHeader('User-Agent', config('token_slayer.anthropic.user_agent'));
    });
});

test('a 400 token response throws UsageProbeException with reason invalid_grant', function () {
    fakeAnthropic(['token' => Http::response(['error' => 'invalid_grant'], 400)]);

    $this->client->exchangeCode('bad-code', 'a-verifier', 'a-state');
})->throws(UsageProbeException::class);

test('a 400 token response carries the invalid_grant reason', function () {
    fakeAnthropic(['token' => Http::response(['error' => 'invalid_grant'], 400)]);

    try {
        $this->client->exchangeCode('bad-code', 'a-verifier', 'a-state');
    } catch (UsageProbeException $exception) {
        expect($exception->reason)->toBe('invalid_grant');

        return;
    }

    $this->fail('Expected UsageProbeException was not thrown.');
});

test('a 429 response throws UsageProbeException with reason rate_limited', function () {
    fakeAnthropic(['usage' => Http::response('', 429)]);

    try {
        $this->client->usage('an-access-token');
    } catch (UsageProbeException $exception) {
        expect($exception->reason)->toBe('rate_limited');

        return;
    }

    $this->fail('Expected UsageProbeException was not thrown.');
});
