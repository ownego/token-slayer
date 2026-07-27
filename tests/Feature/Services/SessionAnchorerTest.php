<?php

use App\Enums\AccountStatus;
use App\Models\Account;
use App\Services\SessionAnchorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->anchorer = app(SessionAnchorer::class);
});

test('a fresh account is sent a one-token anchor message', function () {
    fakeAnthropic();
    $account = Account::factory()->connected()->create(['oauth_expires_at' => now()->addHours(8)]);

    expect($this->anchorer->anchor($account))->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->url() === config('token_slayer.anthropic.messages_endpoint')
        && $request['max_tokens'] === 1
    );
});

test('a disabled account is skipped and sent nothing', function () {
    fakeAnthropic();
    $account = Account::factory()->connected()->create(['status' => AccountStatus::Disabled]);

    expect($this->anchorer->anchor($account))->toBeFalse();
    Http::assertNothingSent();
});

test('an account with no refresh token is skipped and sent nothing', function () {
    fakeAnthropic();
    $account = Account::factory()->create(['oauth_refresh_token' => null]);

    expect($this->anchorer->anchor($account))->toBeFalse();
    Http::assertNothingSent();
});

test('a near-expiry token is refreshed before the anchor message', function () {
    fakeAnthropic();
    $account = Account::factory()->connected()->create(['oauth_expires_at' => now()->subMinute()]);

    expect($this->anchorer->anchor($account))->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->url() === config('token_slayer.anthropic.token_endpoint'));
    Http::assertSent(fn (Request $request) => $request->url() === config('token_slayer.anthropic.messages_endpoint'));
});

test('a rejected anchor message returns false without throwing', function () {
    fakeAnthropic(['messages' => Http::response('', 500)]);
    $account = Account::factory()->connected()->create(['oauth_expires_at' => now()->addHours(8)]);

    expect($this->anchorer->anchor($account))->toBeFalse();
});

test('a swallowed anchor failure is logged with the account and the failure reason', function () {
    Log::spy();
    fakeAnthropic(['messages' => Http::response('', 500)]);
    $account = Account::factory()->connected()->create(['oauth_expires_at' => now()->addHours(8)]);

    $this->anchorer->anchor($account);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => str_contains($message, 'anchor')
            && $context['account_id'] === $account->id
            && $context['reason'] === 'http_error'
        );
});

test('a successful anchor logs nothing', function () {
    Log::spy();
    fakeAnthropic();
    $account = Account::factory()->connected()->create(['oauth_expires_at' => now()->addHours(8)]);

    $this->anchorer->anchor($account);

    Log::shouldNotHaveReceived('warning');
});
