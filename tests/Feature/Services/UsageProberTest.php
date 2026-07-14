<?php

use App\Enums\AccountStatus;
use App\Events\AccountTokenRejected;
use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use App\Services\AnthropicOAuthClient;
use App\Services\UsageProber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->prober = new UsageProber(new AnthropicOAuthClient);
});

test('a disabled account is skipped without any HTTP call', function () {
    fakeAnthropic();
    $account = Account::factory()->connected()->create(['status' => AccountStatus::Disabled]);

    $snapshot = $this->prober->probe($account);

    expect($snapshot)->toBeNull();
    Http::assertNothingSent();
});

test('an account with no refresh token is skipped without any HTTP call', function () {
    fakeAnthropic();
    $account = Account::factory()->create(['oauth_refresh_token' => null]);

    $snapshot = $this->prober->probe($account);

    expect($snapshot)->toBeNull();
    Http::assertNothingSent();
});

test('a fresh token skips refresh and records a snapshot from the usage fixture', function () {
    fakeAnthropic();
    $account = Account::factory()->connected()->create([
        'oauth_expires_at' => now()->addHours(8),
    ]);

    $snapshot = $this->prober->probe($account);

    expect($snapshot)->toBeInstanceOf(AccountUsageSnapshot::class)
        ->and($snapshot->util_5h)->toBe(0)
        ->and($snapshot->util_7d)->toBe(25)
        ->and($snapshot->util_7d_sonnet)->toBeNull()
        ->and($snapshot->util_7d_oi)->toBeNull()
        ->and($snapshot->reset_5h_at)->not->toBeNull()
        ->and($snapshot->reset_7d_at)->not->toBeNull()
        ->and($snapshot->raw)->toHaveKey('limits')
        ->and($snapshot->account_id)->toBe($account->id);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === config('token_slayer.anthropic.usage_endpoint'));

    $account->refresh();
    expect($account->last_probed_at)->not->toBeNull()
        ->and($account->probe_error)->toBeNull();
});

test('a near-expiry token is refreshed before the usage call', function () {
    fakeAnthropic();
    $account = Account::factory()->connected()->create([
        'oauth_access_token' => 'sk-ant-oat01-old',
        'oauth_refresh_token' => 'sk-ant-ort01-old',
        'oauth_expires_at' => now()->addHours(2),
    ]);

    $snapshot = $this->prober->probe($account);

    expect($snapshot)->toBeInstanceOf(AccountUsageSnapshot::class);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->url() === config('token_slayer.anthropic.token_endpoint')
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'sk-ant-ort01-old');
    Http::assertSent(fn ($request) => $request->url() === config('token_slayer.anthropic.usage_endpoint')
        && $request->hasHeader('Authorization', 'Bearer sk-ant-oat01-REDACTED'));

    $account->refresh();
    expect($account->oauth_access_token)->toBe('sk-ant-oat01-REDACTED')
        ->and($account->oauth_refresh_token)->toBe('sk-ant-ort01-REDACTED')
        ->and($account->oauth_expires_at->timestamp)->toBeGreaterThan(now()->addHours(7)->timestamp);
});

test('a null expiry is treated as needing refresh', function () {
    fakeAnthropic();
    $account = Account::factory()->connected()->create(['oauth_expires_at' => null]);

    $this->prober->probe($account);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->url() === config('token_slayer.anthropic.token_endpoint'));
});

test('an invalid_grant refresh failure marks the account needing reauth', function () {
    fakeAnthropic(['token' => Http::response(['error' => ['type' => 'invalid_grant']], 400)]);
    $account = Account::factory()->connected()->create(['oauth_expires_at' => now()->addHours(1)]);

    $snapshot = $this->prober->probe($account);

    expect($snapshot)->toBeNull();
    Http::assertSentCount(1);

    $account->refresh();
    expect($account->status)->toBe(AccountStatus::NeedsReauth)
        ->and($account->probe_error)->not->toBeNull()
        ->and($account->probe_error)->not->toContain('sk-ant')
        ->and($account->probe_error)->not->toContain('old-refresh-token');
});

test('an unauthorized refresh failure marks the account needing reauth', function () {
    fakeAnthropic(['token' => Http::response(['error' => ['type' => 'unauthorized']], 401)]);
    $account = Account::factory()->connected()->create(['oauth_expires_at' => now()->addHours(1)]);

    $snapshot = $this->prober->probe($account);

    expect($snapshot)->toBeNull();

    $account->refresh();
    expect($account->status)->toBe(AccountStatus::NeedsReauth)
        ->and($account->probe_error)->not->toBeNull();
});

test('a transient refresh failure records the error but leaves status active', function () {
    fakeAnthropic(['token' => Http::response('', 503)]);
    $account = Account::factory()->connected()->create(['oauth_expires_at' => now()->addHours(1)]);

    $snapshot = $this->prober->probe($account);

    expect($snapshot)->toBeNull();
    Http::assertSentCount(1);

    $account->refresh();
    expect($account->status)->toBe(AccountStatus::Active)
        ->and($account->probe_error)->not->toBeNull()
        ->and($account->probe_error)->not->toContain('sk-ant');
});

test('a rate-limited usage call returns silently without recording an error', function () {
    fakeAnthropic(['usage' => Http::response('', 429)]);
    $account = Account::factory()->connected()->create(['oauth_expires_at' => now()->addHours(8)]);

    $snapshot = $this->prober->probe($account);

    expect($snapshot)->toBeNull();

    $account->refresh();
    expect($account->status)->toBe(AccountStatus::Active)
        ->and($account->probe_error)->toBeNull()
        ->and($account->last_probed_at)->toBeNull();
});

test('a non-rate-limit usage failure records a safe probe_error', function () {
    fakeAnthropic(['usage' => Http::response('', 500)]);
    $account = Account::factory()->connected()->create(['oauth_expires_at' => now()->addHours(8)]);

    $snapshot = $this->prober->probe($account);

    expect($snapshot)->toBeNull();

    $account->refresh();
    expect($account->status)->toBe(AccountStatus::Active)
        ->and($account->probe_error)->not->toBeNull()
        ->and($account->probe_error)->not->toContain('sk-ant');
});

test('a rate-limited refresh is silent and leaves status and probe_error untouched', function () {
    fakeAnthropic(['token' => Http::response(['error' => ['type' => 'rate_limited']], 429)]);

    $account = Account::factory()->connected()->create([
        'oauth_expires_at' => now()->addMinutes(30), // within headroom → triggers refresh
        'probe_error' => null,
    ]);

    $result = app(UsageProber::class)->probe($account);

    $account->refresh();

    expect($result)->toBeNull()
        ->and($account->status)->toBe(AccountStatus::Active)
        ->and($account->probe_error)->toBeNull();
});

test('it dispatches AccountTokenRejected when a refresh returns invalid_grant', function () {
    Event::fake([AccountTokenRejected::class]);
    fakeAnthropic(['token' => Http::response(['error' => ['type' => 'invalid_grant']], 400)]);
    $account = Account::factory()->connected()->create(['oauth_expires_at' => now()->addHours(1)]);

    $this->prober->probe($account);

    Event::assertDispatched(AccountTokenRejected::class, function (AccountTokenRejected $event) use ($account) {
        return $event->account->is($account) && $event->reason === 'invalid_grant';
    });
});

test('it dispatches AccountTokenRejected when a refresh returns unauthorized', function () {
    Event::fake([AccountTokenRejected::class]);
    // 403 (not 401) so AnthropicOAuthClient::decodeOrFail() actually classifies
    // this as 'unauthorized' rather than 'invalid_grant' — for a token call it
    // maps 400/401 to invalid_grant unconditionally and only reaches the
    // unauthorized branch on 401/403 when the 400/401 check didn't match.
    fakeAnthropic(['token' => Http::response(['error' => ['type' => 'unauthorized']], 403)]);
    $account = Account::factory()->connected()->create(['oauth_expires_at' => now()->addHours(1)]);

    $this->prober->probe($account);

    Event::assertDispatched(AccountTokenRejected::class, fn (AccountTokenRejected $event) => $event->reason === 'unauthorized');
});

test('it does not dispatch AccountTokenRejected on a transient refresh failure', function () {
    Event::fake([AccountTokenRejected::class]);
    fakeAnthropic(['token' => Http::response('', 503)]);
    $account = Account::factory()->connected()->create(['oauth_expires_at' => now()->addHours(1)]);

    $this->prober->probe($account);

    Event::assertNotDispatched(AccountTokenRejected::class);
});

test('it does not dispatch AccountTokenRejected on a rate-limited refresh', function () {
    Event::fake([AccountTokenRejected::class]);
    fakeAnthropic(['token' => Http::response(['error' => ['type' => 'rate_limited']], 429)]);
    $account = Account::factory()->connected()->create(['oauth_expires_at' => now()->addMinutes(30)]);

    $this->prober->probe($account);

    Event::assertNotDispatched(AccountTokenRejected::class);
});

test('it does not dispatch AccountTokenRejected when the account is already NeedsReauth', function () {
    Event::fake([AccountTokenRejected::class]);
    fakeAnthropic(['token' => Http::response(['error' => ['type' => 'invalid_grant']], 400)]);
    // Constructed directly with a NeedsReauth original status; scopeProbeable
    // would normally exclude it, so we exercise the guard in isolation.
    $account = Account::factory()->needsReauth()->create(['oauth_expires_at' => now()->addHours(1)]);

    $this->prober->probe($account);

    Event::assertNotDispatched(AccountTokenRejected::class);
});

test('a rejected refresh posts one alert to the configured security webhook end to end', function () {
    config(['services.slack_security.webhook_url' => 'https://hooks.slack.test/security']);
    fakeAnthropic(['token' => Http::response(['error' => ['type' => 'invalid_grant']], 400)]);
    Http::fake(['https://hooks.slack.test/*' => Http::response('', 200)]);
    $account = Account::factory()->connected()->create([
        'email' => 'compromised@example.com',
        'oauth_expires_at' => now()->addHours(1),
    ]);

    $this->prober->probe($account);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://hooks.slack.test/security'
            && str_contains(json_encode($request->data()), 'compromised@example.com');
    });
});
