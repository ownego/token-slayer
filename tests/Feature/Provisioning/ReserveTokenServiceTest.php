<?php

use App\Exceptions\AccountConnectException;
use App\Models\Account;
use App\Models\AccountReserveToken;
use App\Models\User;
use App\Services\AccountConnectService;
use App\Services\Provisioning\ReserveTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('mints a reserve token with the exact session deadline from the exchange', function (): void {
    fakeAnthropic();
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create(['email' => 'ongtung2212002@gmail.com']);
    $state = app(AccountConnectService::class)->start()['state'];

    $token = app(ReserveTokenService::class)->mint($account, $admin, $state, 'pasted-code');

    expect($token->account_id)->toBe($account->id)
        ->and($token->created_by)->toBe($admin->id)
        ->and($token->token_uuid)->toBe('0026ca03-e219-4196-90c1-c9f3f64053d2')
        ->and($token->refresh_token)->toBe('sk-ant-ort01-REDACTED')
        ->and($token->session_expires_at_estimated)->toBeFalse()
        ->and((int) round(now()->diffInSeconds($token->session_expires_at)))->toBeGreaterThanOrEqual(2546270)
        ->and((int) round(now()->diffInSeconds($token->session_expires_at)))->toBeLessThanOrEqual(2546274);
});

it('falls back to the typical lifetime, flagged estimated, when the exchange omits it', function (): void {
    $body = json_decode(file_get_contents(base_path('tests/fixtures/anthropic/token.json')), true);
    unset($body['refresh_token_expires_in']);
    fakeAnthropic(['token' => Http::response($body, 200)]);
    $account = Account::factory()->create(['email' => 'ongtung2212002@gmail.com']);
    $state = app(AccountConnectService::class)->start()['state'];

    $token = app(ReserveTokenService::class)->mint($account, null, $state, 'pasted-code');

    expect($token->session_expires_at_estimated)->toBeTrue()
        ->and($token->session_expires_at->isFuture())->toBeTrue();
});

it('rejects a code authorized for a different account', function (): void {
    fakeAnthropic();
    $account = Account::factory()->create(['email' => 'someone-else@example.com', 'organization_uuid' => 'other-org']);
    $state = app(AccountConnectService::class)->start()['state'];

    expect(fn () => app(ReserveTokenService::class)->mint($account, null, $state, 'pasted-code'))
        ->toThrow(AccountConnectException::class);
    expect(AccountReserveToken::query()->count())->toBe(0);
});

it('counts only available tokens and drops a discarded one', function (): void {
    $account = Account::factory()->create();
    $kept = AccountReserveToken::factory()->for($account)->create();
    $dropped = AccountReserveToken::factory()->for($account)->create();
    AccountReserveToken::factory()->for($account)->expired()->create();
    $service = app(ReserveTokenService::class);

    $service->discard($dropped);

    expect($service->availableCount($account))->toBe(1)
        ->and($service->availableFor($account)->pluck('id')->all())->toBe([$kept->id]);
});
