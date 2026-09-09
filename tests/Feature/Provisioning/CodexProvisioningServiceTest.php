<?php

use App\Enums\AccountStatus;
use App\Enums\GrantStatus;
use App\Enums\MembershipStatus;
use App\Enums\Provider;
use App\Exceptions\CodexConnectException;
use App\Models\Account;
use App\Models\User;
use App\Services\CodexProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// {"exp": 4102444800}
const ACCESS_TOKEN_PAYLOAD_B64 = 'eyJleHAiOiA0MTAyNDQ0ODAwfQ';

/**
 * Builds a fake `auth.json`'s id_token, carrying `chatgpt_account_id:
 * "acct-1"` (etc.) under the `https://api.openai.com/auth` namespace, plus
 * top-level `email` and `sid` (the login-session identity two different
 * `--for` calls off the SAME admin login would share).
 *
 * @param  string  $sid  the login session id (OpenAI's `sid` claim)
 * @return string
 */
function fakeCodexIdToken(string $sid = 'authsess-shared-1'): string
{
    return 'h.'.base64_encode(json_encode([
        'email' => 'shared@example.com',
        'sid' => $sid,
        'https://api.openai.com/auth' => [
            'chatgpt_account_id' => 'acct-1',
            'chatgpt_user_id' => 'user-1',
            'chatgpt_plan_type' => 'pro',
        ],
    ])).'.s';
}

function fakeCodexAuthJson(string $sid = 'authsess-shared-1'): array
{
    return [
        'auth_mode' => 'chatgpt',
        'OPENAI_API_KEY' => null,
        'tokens' => [
            'id_token' => fakeCodexIdToken($sid),
            'access_token' => 'h.'.ACCESS_TOKEN_PAYLOAD_B64.'.s',
            'refresh_token' => 'opaque-refresh-fixture',
            'account_id' => 'acct-1',
        ],
        'last_refresh' => '2026-01-01T00:00:00Z',
    ];
}

it('connects a new Codex account, decoding identity from id_token', function (): void {
    $account = app(CodexProvisioningService::class)->connectAccount(fakeCodexAuthJson(), 'Company ChatGPT');

    expect($account->provider)->toBe(Provider::Codex)
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

it('codex_credentials.last_probed_at persists and reads back a real timestamp', function (): void {
    $account = app(CodexProvisioningService::class)->connectAccount(fakeCodexAuthJson(), 'Company ChatGPT');
    $probedAt = now();

    $account->codexCredential->last_probed_at = $probedAt;
    $account->codexCredential->save();

    expect($account->codexCredential->fresh()->last_probed_at->timestamp)->toBe($probedAt->timestamp);
});

it('persists earliest_refresh_at onto the credential when the auth.json carries it (the device-code path)', function (): void {
    $authJson = fakeCodexAuthJson();
    $authJson['earliest_refresh_at'] = now()->addDays(9)->timestamp;

    $account = app(CodexProvisioningService::class)->connectAccount($authJson, 'Company ChatGPT');

    expect($account->codexCredential->earliest_refresh_at->timestamp)
        ->toBe((int) $authJson['earliest_refresh_at']);
});

it('leaves earliest_refresh_at null when the auth.json does not carry it (the CLI-sourced path)', function (): void {
    $account = app(CodexProvisioningService::class)->connectAccount(fakeCodexAuthJson(), 'Company ChatGPT');

    expect($account->codexCredential->earliest_refresh_at)->toBeNull();
});

it('connects a Codex account whose email already belongs to an existing Claude account', function (): void {
    Account::factory()->create(['provider' => 'claude', 'email' => 'shared@example.com']);

    $account = app(CodexProvisioningService::class)->connectAccount(fakeCodexAuthJson(), 'Company ChatGPT');

    expect($account->provider)->toBe(Provider::Codex)
        ->and($account->email)->toBe('shared@example.com')
        ->and(Account::where('email', 'shared@example.com')->count())->toBe(2);
});

it('re-connecting the same chatgpt_account_id updates the existing account instead of duplicating it', function (): void {
    $service = app(CodexProvisioningService::class);
    $first = $service->connectAccount(fakeCodexAuthJson(), 'Company ChatGPT');
    $second = $service->connectAccount(fakeCodexAuthJson(), 'Renamed ChatGPT');

    expect($second->id)->toBe($first->id)
        ->and($second->fresh()->name)->toBe('Renamed ChatGPT')
        ->and(Account::where('provider', 'codex')->count())->toBe(1);
});

it('provisions a device, syncs Tracked membership, and writes the raw auth_json onto the grant', function (): void {
    $account = app(CodexProvisioningService::class)->connectAccount(fakeCodexAuthJson(), 'Company ChatGPT');
    $user = User::factory()->create();

    $grant = app(CodexProvisioningService::class)->provisionForDevice($account, $user, fakeCodexAuthJson());

    expect($grant->status)->toBe(GrantStatus::Pending)
        ->and($grant->account_id)->toBe($account->id)
        ->and($user->accounts()->wherePivot('status', MembershipStatus::Tracked->value)->whereKey($account->id)->exists())->toBeTrue()
        ->and($grant->pending_codex_auth_json)->toBe(fakeCodexAuthJson());
});

it('rejects provisioning a second employee off the exact same admin login session', function (): void {
    // The admin re-uploads whatever is currently in their local auth.json --
    // it mints no fresh token per call, unlike Claude's PKCE flow. Handing
    // the same login (same `sid`) to two different employees means they'd
    // share one refresh token, and whichever one's Codex CLI refreshes
    // first silently kills the other's copy.
    $account = app(CodexProvisioningService::class)->connectAccount(fakeCodexAuthJson(), 'Company ChatGPT');
    $first = User::factory()->create();
    $second = User::factory()->create();
    app(CodexProvisioningService::class)->provisionForDevice($account, $first, fakeCodexAuthJson('authsess-shared-1'));

    try {
        app(CodexProvisioningService::class)->provisionForDevice($account, $second, fakeCodexAuthJson('authsess-shared-1'));
        test()->fail('expected a CodexConnectException');
    } catch (CodexConnectException $exception) {
        expect($exception->reason)->toBe('codex_connect_session_already_assigned');
    }
});

it('allows provisioning two employees when each carries its own distinct login session', function (): void {
    $account = app(CodexProvisioningService::class)->connectAccount(fakeCodexAuthJson(), 'Company ChatGPT');
    $first = User::factory()->create();
    $second = User::factory()->create();

    app(CodexProvisioningService::class)->provisionForDevice($account, $first, fakeCodexAuthJson('authsess-1'));
    $secondGrant = app(CodexProvisioningService::class)->provisionForDevice($account, $second, fakeCodexAuthJson('authsess-2'));

    expect($secondGrant->status)->toBe(GrantStatus::Pending);
});

it('allows re-provisioning the same employee again off the same session', function (): void {
    $account = app(CodexProvisioningService::class)->connectAccount(fakeCodexAuthJson(), 'Company ChatGPT');
    $user = User::factory()->create();
    app(CodexProvisioningService::class)->provisionForDevice($account, $user, fakeCodexAuthJson('authsess-shared-1'));

    $again = app(CodexProvisioningService::class)->provisionForDevice($account, $user, fakeCodexAuthJson('authsess-shared-1'));

    expect($again->status)->toBe(GrantStatus::Pending);
});

it('rejects a Step B upload whose chatgpt_account_id does not match the target account', function (): void {
    $account = app(CodexProvisioningService::class)->connectAccount(fakeCodexAuthJson(), 'Company ChatGPT');
    $user = User::factory()->create();

    $mismatched = fakeCodexAuthJson();
    $mismatched['tokens']['id_token'] = 'h.'.base64_encode(json_encode([
        'https://api.openai.com/auth' => ['chatgpt_account_id' => 'different-acct'],
    ])).'.s';

    try {
        app(CodexProvisioningService::class)->provisionForDevice($account, $user, $mismatched);
        test()->fail('expected a CodexConnectException');
    } catch (CodexConnectException $exception) {
        expect($exception->reason)->toBe('codex_connect_identity_mismatch');
    }
});

it('rejects an upload whose id_token carries no chatgpt_account_id, with a machine-readable reason', function (): void {
    $badAuthJson = fakeCodexAuthJson();
    $badAuthJson['tokens']['id_token'] = 'h.'.base64_encode(json_encode(['email' => 'x@example.com'])).'.s';

    try {
        app(CodexProvisioningService::class)->connectAccount($badAuthJson, 'Company ChatGPT');
        test()->fail('expected a CodexConnectException');
    } catch (CodexConnectException $exception) {
        expect($exception->reason)->toBe('codex_connect_invalid_authjson');
    }
});

it('revoke calls the OpenAI revoke endpoint with the cached refresh token, then marks the grant revoked', function (): void {
    Http::fake(['auth.openai.com/oauth/revoke' => Http::response([], 200)]);
    $account = app(CodexProvisioningService::class)->connectAccount(fakeCodexAuthJson(), 'Company ChatGPT');
    $user = User::factory()->create();
    $grant = app(CodexProvisioningService::class)->provisionForDevice($account, $user, fakeCodexAuthJson());

    app(CodexProvisioningService::class)->revoke($grant);

    Http::assertSent(fn ($request) => $request['token'] === 'opaque-refresh-fixture');
    expect($grant->fresh()->status)->toBe(GrantStatus::Revoked)
        ->and($grant->fresh()->pending_codex_auth_json)->toBeNull();
});
