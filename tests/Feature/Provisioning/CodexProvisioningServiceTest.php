<?php

use App\Enums\AccountStatus;
use App\Enums\GrantStatus;
use App\Enums\MembershipStatus;
use App\Exceptions\CodexConnectException;
use App\Models\Account;
use App\Models\User;
use App\Services\CodexProvisioningService;
use App\Support\CacheKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// {"chatgpt_account_id": "acct-1", "chatgpt_user_id": "user-1", "chatgpt_plan_type": "pro", "email": "shared@example.com"}
// under the "https://api.openai.com/auth" namespace, plus top-level "email" and "exp": 4102444800
const ID_TOKEN_PAYLOAD_B64 = 'eyJlbWFpbCI6ICJzaGFyZWRAZXhhbXBsZS5jb20iLCAiaHR0cHM6Ly9hcGkub3BlbmFpLmNvbS9hdXRoIjogeyJjaGF0Z3B0X2FjY291bnRfaWQiOiAiYWNjdC0xIiwgImNoYXRncHRfdXNlcl9pZCI6ICJ1c2VyLTEiLCAiY2hhdGdwdF9wbGFuX3R5cGUiOiAicHJvIn19';
// {"exp": 4102444800}
const ACCESS_TOKEN_PAYLOAD_B64 = 'eyJleHAiOiA0MTAyNDQ0ODAwfQ';

function fakeCodexAuthJson(): array
{
    return [
        'auth_mode' => 'chatgpt',
        'OPENAI_API_KEY' => null,
        'tokens' => [
            'id_token' => 'h.'.ID_TOKEN_PAYLOAD_B64.'.s',
            'access_token' => 'h.'.ACCESS_TOKEN_PAYLOAD_B64.'.s',
            'refresh_token' => 'opaque-refresh-fixture',
            'account_id' => 'acct-1',
        ],
        'last_refresh' => '2026-01-01T00:00:00Z',
    ];
}

it('connects a new Codex account, decoding identity from id_token', function (): void {
    $account = app(CodexProvisioningService::class)->connectAccount(fakeCodexAuthJson(), 'Company ChatGPT');

    expect($account->provider)->toBe('codex')
        ->and($account->name)->toBe('Company ChatGPT')
        ->and($account->email)->toBe('shared@example.com');

    $credential = $account->codexCredential;
    expect($credential->chatgpt_account_id)->toBe('acct-1')
        ->and($credential->chatgpt_user_id)->toBe('user-1')
        ->and($credential->plan_type)->toBe('pro')
        ->and($credential->codex_access_token)->toBe(fakeCodexAuthJson()['tokens']['access_token'])
        ->and($credential->codex_refresh_token)->toBe('opaque-refresh-fixture')
        ->and($credential->status)->toBe(AccountStatus::Active)
        ->and($credential->earliest_refresh_at)->toBeNull()
        ->and($credential->last_refreshed_at->toIso8601String())->toBe('2026-01-01T00:00:00+00:00');
});

it('re-connecting the same chatgpt_account_id updates the existing account instead of duplicating it', function (): void {
    $service = app(CodexProvisioningService::class);
    $first = $service->connectAccount(fakeCodexAuthJson(), 'Company ChatGPT');
    $second = $service->connectAccount(fakeCodexAuthJson(), 'Renamed ChatGPT');

    expect($second->id)->toBe($first->id)
        ->and($second->fresh()->name)->toBe('Renamed ChatGPT')
        ->and(Account::where('provider', 'codex')->count())->toBe(1);
});

it('provisions a device, syncs Tracked membership, and caches the raw auth_json', function (): void {
    $account = app(CodexProvisioningService::class)->connectAccount(fakeCodexAuthJson(), 'Company ChatGPT');
    $user = User::factory()->create();

    $grant = app(CodexProvisioningService::class)->provisionForDevice($account, $user, fakeCodexAuthJson());

    expect($grant->status)->toBe(GrantStatus::Pending)
        ->and($grant->account_id)->toBe($account->id)
        ->and($user->accounts()->wherePivot('status', MembershipStatus::Tracked->value)->whereKey($account->id)->exists())->toBeTrue();

    $cached = json_decode(Crypt::decryptString(
        Cache::get(CacheKeys::provisionedGrant($grant->id))
    ), true);
    expect($cached['provider'])->toBe('codex')
        ->and($cached['chatgpt_account_id'])->toBe('acct-1')
        ->and($cached['auth_json'])->toBe(fakeCodexAuthJson());
});

it('rejects a Step B upload whose chatgpt_account_id does not match the target account', function (): void {
    $account = app(CodexProvisioningService::class)->connectAccount(fakeCodexAuthJson(), 'Company ChatGPT');
    $user = User::factory()->create();

    $mismatched = fakeCodexAuthJson();
    $mismatched['tokens']['id_token'] = 'h.'.base64_encode(json_encode([
        'https://api.openai.com/auth' => ['chatgpt_account_id' => 'different-acct'],
    ])).'.s';

    expect(fn () => app(CodexProvisioningService::class)->provisionForDevice($account, $user, $mismatched))
        ->toThrow(CodexConnectException::class);
});

it('revoke calls the OpenAI revoke endpoint with the cached refresh token, then marks the grant revoked', function (): void {
    Http::fake(['auth.openai.com/oauth/revoke' => Http::response([], 200)]);
    $account = app(CodexProvisioningService::class)->connectAccount(fakeCodexAuthJson(), 'Company ChatGPT');
    $user = User::factory()->create();
    $grant = app(CodexProvisioningService::class)->provisionForDevice($account, $user, fakeCodexAuthJson());

    app(CodexProvisioningService::class)->revoke($grant);

    Http::assertSent(fn ($request) => $request['token'] === 'opaque-refresh-fixture');
    expect($grant->fresh()->status)->toBe(GrantStatus::Revoked)
        ->and(Cache::get(CacheKeys::provisionedGrant($grant->id)))->toBeNull();
});
