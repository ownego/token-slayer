<?php

use App\Enums\AccountStatus;
use App\Exceptions\AccountConnectException;
use App\Models\Account;
use App\Services\AccountConnectService;
use App\Services\Connect\ConnectResolution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(AccountConnectService::class);
});

test('start caches only the verifier under the state key and builds an authorize url', function () {
    $result = $this->service->start();

    expect($result)->toHaveKeys(['url', 'state'])
        ->and($result['state'])->not->toBeEmpty();

    $cached = Cache::get("account-connect:{$result['state']}");
    expect($cached)->not->toBeNull()
        ->and($cached['verifier'])->not->toBeEmpty()
        ->and($cached)->not->toHaveKey('account_id');

    expect($result['url'])->toStartWith('https://claude.com/cai/oauth/authorize')
        ->and($result['url'])->toContain('state='.$result['state'])
        ->and($result['url'])->toContain('code_challenge_method=S256');
});

test('resolve updates an existing account matched by email and marks it active, then probes', function () {
    fakeAnthropic();
    $account = Account::factory()->create(['email' => 'ongtung2212002@gmail.com', 'status' => AccountStatus::NeedsReauth]);
    $started = $this->service->start();

    $resolution = $this->service->resolve($started['state'], 'pasted-code#'.$started['state']);

    expect($resolution)->toBeInstanceOf(ConnectResolution::class)
        ->and($resolution->isExisting())->toBeTrue()
        ->and($resolution->account->id)->toBe($account->id);

    $account->refresh();
    expect($account->oauth_access_token)->toBe('sk-ant-oat01-REDACTED')
        ->and($account->oauth_refresh_token)->toBe('sk-ant-ort01-REDACTED')
        ->and($account->account_uuid)->toBe('adfeaf9f-dd9c-4c03-93c2-0bb05c7278b9')
        ->and($account->organization_uuid)->toBe('7f993a12-f480-45cd-8b99-1e3182d168bf')
        ->and($account->plan)->toBe('claude_pro')
        ->and($account->status)->toBe(AccountStatus::Active)
        ->and($account->last_probed_at)->not->toBeNull();

    Http::assertSent(fn ($request) => $request->url() === config('token_slayer.anthropic.token_endpoint')
        && $request['code'] === 'pasted-code');
});

test('resolve updates an existing account matched by organization uuid when the email differs', function () {
    fakeAnthropic();
    $account = Account::factory()->create([
        'email' => 'old-address@example.com',
        'organization_uuid' => '7f993a12-f480-45cd-8b99-1e3182d168bf',
    ]);
    $started = $this->service->start();

    $resolution = $this->service->resolve($started['state'], 'pasted-code');

    expect($resolution->isExisting())->toBeTrue()
        ->and($resolution->account->id)->toBe($account->id);
    expect($account->refresh()->oauth_access_token)->toBe('sk-ant-oat01-REDACTED');
});

test('resolve matched by organization uuid reconciles the stale stored email to the profile email', function () {
    fakeAnthropic();
    $account = Account::factory()->create([
        'email' => 'placeholder@example.com',
        'organization_uuid' => '7f993a12-f480-45cd-8b99-1e3182d168bf',
    ]);
    $started = $this->service->start();

    $this->service->resolve($started['state'], 'pasted-code');

    expect($account->fresh()->email)->toBe('ongtung2212002@gmail.com')
        ->and($account->fresh()->status)->toBe(AccountStatus::Active);
});

test('resolve matched by organization uuid does not steal an email already held by another account', function () {
    fakeAnthropic();
    $account = Account::factory()->create([
        'email' => 'placeholder@example.com',
        'organization_uuid' => '7f993a12-f480-45cd-8b99-1e3182d168bf',
    ]);
    Account::factory()->create(['email' => 'ongtung2212002@gmail.com']);
    $started = $this->service->start();

    // Pass $account as the expected match to force the org-uuid path directly
    // (open resolve() would match the other row by email first, never
    // reaching this row's reconcileIdentity call at all).
    $this->service->resolve($started['state'], 'pasted-code', $account);

    expect($account->fresh()->email)->toBe('placeholder@example.com');
});

test('resolve returns a pending draft and stashes token material for a brand-new identity', function () {
    fakeAnthropic();
    $started = $this->service->start();

    $resolution = $this->service->resolve($started['state'], 'pasted-code');

    expect($resolution->isExisting())->toBeFalse()
        ->and($resolution->draft->email)->toBe('ongtung2212002@gmail.com')
        ->and($resolution->draft->orgUuid)->toBe('7f993a12-f480-45cd-8b99-1e3182d168bf')
        ->and($resolution->draft->plan)->toBe('claude_pro')
        ->and($resolution->draft->handoffKey)->not->toBeEmpty();

    $stashed = Cache::get("account-connect-pending:{$resolution->draft->handoffKey}");
    expect($stashed['access_token'])->toBe('sk-ant-oat01-REDACTED')
        ->and($stashed['email'])->toBe('ongtung2212002@gmail.com');

    expect(Account::count())->toBe(0);
});

test('resolve with an expected account updates it when the identity matches', function () {
    fakeAnthropic();
    $account = Account::factory()->create(['email' => 'ongtung2212002@gmail.com', 'status' => AccountStatus::NeedsReauth]);
    $started = $this->service->start();

    $resolution = $this->service->resolve($started['state'], 'pasted-code', $account);

    expect($resolution->account->id)->toBe($account->id)
        ->and($account->refresh()->status)->toBe(AccountStatus::Active);
});

test('resolve with an expected account throws identity mismatch and writes nothing', function () {
    fakeAnthropic();
    $account = Account::factory()->create(['email' => 'someone-else@example.com']);
    $started = $this->service->start();

    try {
        $this->service->resolve($started['state'], 'pasted-code', $account);
        $this->fail('Expected AccountConnectException');
    } catch (AccountConnectException $e) {
        expect($e->reason)->toBe('connect_identity_mismatch')
            ->and($e->getMessage())->toContain('ongtung2212002@gmail.com');
    }

    $account->refresh();
    expect($account->oauth_access_token)->toBeNull()
        ->and($account->status)->toBe(AccountStatus::Active);
    Http::assertNotSent(fn ($request) => $request->url() === config('token_slayer.anthropic.usage_endpoint'));
});

test('resolve throws no-identity when the profile has no email', function () {
    fakeAnthropic(['profile' => Http::response(['account' => ['uuid' => 'x'], 'organization' => ['uuid' => 'y']], 200)]);
    $started = $this->service->start();

    try {
        $this->service->resolve($started['state'], 'pasted-code');
        $this->fail('Expected AccountConnectException');
    } catch (AccountConnectException $e) {
        expect($e->reason)->toBe('connect_no_identity');
    }
});

test('resolve throws state expired when the state is missing and never calls the api', function () {
    fakeAnthropic();

    expect(fn () => $this->service->resolve('not-a-real-state', 'some-code'))
        ->toThrow(AccountConnectException::class);

    Http::assertNothingSent();
});

test('resolve is single-use: replaying the same state after success fails', function () {
    fakeAnthropic();
    Account::factory()->create(['email' => 'ongtung2212002@gmail.com']);
    $started = $this->service->start();

    $this->service->resolve($started['state'], 'pasted-code');

    expect(fn () => $this->service->resolve($started['state'], 'pasted-code'))
        ->toThrow(AccountConnectException::class);
});

test('createFromPending creates a new active account with the edited plan and name', function () {
    fakeAnthropic();
    $started = $this->service->start();
    $draft = $this->service->resolve($started['state'], 'pasted-code')->draft;

    $account = $this->service->createFromPending($draft->handoffKey, 'max-5x', 'Custom Name');

    expect($account->exists)->toBeTrue()
        ->and($account->email)->toBe('ongtung2212002@gmail.com')
        ->and($account->organization_uuid)->toBe('7f993a12-f480-45cd-8b99-1e3182d168bf')
        ->and($account->plan)->toBe('max-5x')
        ->and($account->name)->toBe('Custom Name')
        ->and($account->oauth_access_token)->toBe('sk-ant-oat01-REDACTED')
        ->and($account->status)->toBe(AccountStatus::Active)
        ->and($account->last_probed_at)->not->toBeNull();

    expect(Cache::get("account-connect-pending:{$draft->handoffKey}"))->toBeNull();
});

test('createFromPending updates an existing row instead of duplicating when a race created it', function () {
    fakeAnthropic();
    $started = $this->service->start();
    $draft = $this->service->resolve($started['state'], 'pasted-code')->draft;

    // Simulate another admin creating the same identity in the meantime.
    $racer = Account::factory()->create(['email' => 'ongtung2212002@gmail.com']);

    $account = $this->service->createFromPending($draft->handoffKey, 'max-20x', 'Whatever');

    expect($account->id)->toBe($racer->id)
        ->and(Account::where('email', 'ongtung2212002@gmail.com')->count())->toBe(1)
        ->and($account->oauth_access_token)->toBe('sk-ant-oat01-REDACTED');
});

test('createFromPending throws state expired when the handoff key is unknown', function () {
    expect(fn () => $this->service->createFromPending('no-such-key', 'max-20x', null))
        ->toThrow(AccountConnectException::class);
});

test('disconnect wipes the stored grant and marks the account needing re-auth', function () {
    $account = Account::factory()->connected()->create();

    $this->service->disconnect($account);

    $account->refresh();
    expect($account->oauth_access_token)->toBeNull()
        ->and($account->oauth_refresh_token)->toBeNull()
        ->and($account->status)->toBe(AccountStatus::NeedsReauth)
        ->and($account->probe_error)->toBe('disconnected by admin');
});
