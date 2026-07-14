<?php

use App\Enums\AccountStatus;
use App\Exceptions\AccountConnectException;
use App\Models\Account;
use App\Services\AccountConnectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(AccountConnectService::class);
});

test('start caches the verifier under the state key and builds an authorize url', function () {
    $account = Account::factory()->create();

    $result = $this->service->start($account);

    expect($result)->toHaveKeys(['url', 'state'])
        ->and($result['state'])->not->toBeEmpty();

    $cached = Cache::get("account-connect:{$result['state']}");
    expect($cached)->not->toBeNull()
        ->and($cached['account_id'])->toBe($account->id)
        ->and($cached['verifier'])->not->toBeEmpty();

    expect($result['url'])->toStartWith('https://claude.com/cai/oauth/authorize')
        ->and($result['url'])->toContain('state='.$result['state'])
        ->and($result['url'])->toContain('code_challenge=')
        ->and($result['url'])->toContain('code_challenge_method=S256')
        ->and($result['url'])->toContain('response_type=code');
});

test('complete happy path stores encrypted tokens, org uuid, plan, and active status, then probes', function () {
    fakeAnthropic();
    $account = Account::factory()->create(['email' => 'ongtung2212002@gmail.com']);

    $started = $this->service->start($account);

    $result = $this->service->complete($started['state'], 'the-pasted-code#'.$started['state']);

    expect($result->id)->toBe($account->id);

    $account->refresh();
    expect($account->oauth_access_token)->toBe('sk-ant-oat01-REDACTED')
        ->and($account->oauth_refresh_token)->toBe('sk-ant-ort01-REDACTED')
        ->and($account->oauth_expires_at)->not->toBeNull()
        ->and($account->account_uuid)->toBe('adfeaf9f-dd9c-4c03-93c2-0bb05c7278b9')
        ->and($account->organization_uuid)->toBe('7f993a12-f480-45cd-8b99-1e3182d168bf')
        ->and($account->plan)->toBe('default_claude_ai')
        ->and($account->status)->toBe(AccountStatus::Active)
        ->and($account->probe_error)->toBeNull()
        ->and($account->last_probed_at)->not->toBeNull();

    Http::assertSent(fn ($request) => $request->url() === config('token_slayer.anthropic.token_endpoint')
        && $request['code'] === 'the-pasted-code'
        && $request['state'] === $started['state']);
    Http::assertSent(fn ($request) => $request->url() === config('token_slayer.anthropic.usage_endpoint'));

    expect(Cache::get("account-connect:{$started['state']}"))->toBeNull();
});

test('complete throws on email mismatch and stores nothing', function () {
    fakeAnthropic();
    $account = Account::factory()->create(['email' => 'someone-else@example.com']);

    $started = $this->service->start($account);

    expect(fn () => $this->service->complete($started['state'], 'the-pasted-code'))
        ->toThrow(AccountConnectException::class);

    $account->refresh();
    expect($account->oauth_access_token)->toBeNull()
        ->and($account->oauth_refresh_token)->toBeNull()
        ->and($account->status)->toBe(AccountStatus::Active);

    Http::assertNotSent(fn ($request) => $request->url() === config('token_slayer.anthropic.usage_endpoint'));
});

test('complete throws on expired or missing state', function () {
    fakeAnthropic();

    expect(fn () => $this->service->complete('not-a-real-state', 'some-code'))
        ->toThrow(AccountConnectException::class);

    Http::assertNothingSent();
});

test('complete is single-use: replaying the same state after success fails', function () {
    fakeAnthropic();
    $account = Account::factory()->create(['email' => 'ongtung2212002@gmail.com']);
    $started = $this->service->start($account);

    $this->service->complete($started['state'], 'the-pasted-code#'.$started['state']);

    expect(fn () => $this->service->complete($started['state'], 'the-pasted-code#'.$started['state']))
        ->toThrow(AccountConnectException::class);
});

test('disconnect wipes the stored grant and marks the account needing re-auth', function () {
    $account = Account::factory()->connected()->create();

    $this->service->disconnect($account);

    $account->refresh();

    expect($account->oauth_access_token)->toBeNull()
        ->and($account->oauth_refresh_token)->toBeNull()
        ->and($account->oauth_expires_at)->toBeNull()
        ->and($account->status)->toBe(AccountStatus::NeedsReauth)
        ->and($account->probe_error)->toBe('disconnected by admin');
});
