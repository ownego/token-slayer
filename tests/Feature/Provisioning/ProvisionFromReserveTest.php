<?php

use App\Enums\GrantStatus;
use App\Exceptions\ReserveTokenUnavailableException;
use App\Models\Account;
use App\Models\AccountProvisionedGrant;
use App\Models\AccountReserveToken;
use App\Models\Device;
use App\Models\User;
use App\Services\AccountProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->account = Account::factory()->create();
    $this->user = User::factory()->create();
    $this->device = Device::factory()->for($this->user)->create(['device_id' => 'fp-1']);
    $this->service = app(AccountProvisioningService::class);
});

it('turns a reserve token into a pending grant that keeps the token secret and its remaining session life', function (): void {
    $deadline = now()->addDays(9)->startOfSecond();
    $reserve = AccountReserveToken::factory()->for($this->account)->create([
        'access_token' => 'sk-ant-oat01-reserve',
        'refresh_token' => 'sk-ant-ort01-reserve',
        'token_uuid' => 'uuid-reserve',
        'session_expires_at' => $deadline,
        'session_expires_at_estimated' => true,
    ]);

    $grant = $this->service->provisionFromReserve($this->user, $this->account, $this->device, $reserve->id);

    expect($grant->status)->toBe(GrantStatus::Pending)
        ->and($grant->pending_claude_access_token)->toBe('sk-ant-oat01-reserve')
        ->and($grant->pending_claude_refresh_token)->toBe('sk-ant-ort01-reserve')
        ->and($grant->token_uuid)->toBe('uuid-reserve')
        ->and($grant->session_expires_at->equalTo($deadline))->toBeTrue()
        ->and($grant->session_expires_at_estimated)->toBeTrue()
        ->and($reserve->fresh()->used_at)->not->toBeNull()
        ->and($reserve->fresh()->used_grant_id)->toBe($grant->id);
});

it('revokes the device\'s previous live grant when assigning from reserve', function (): void {
    $old = AccountProvisionedGrant::factory()->for($this->account)->for($this->device)->claimed()->create();
    $reserve = AccountReserveToken::factory()->for($this->account)->create();

    $this->service->provisionFromReserve($this->user, $this->account, $this->device, $reserve->id);

    expect($old->fresh()->status)->toBe(GrantStatus::Revoked);
});

it('refuses a reserve token that cannot be assigned and writes nothing', function (string $state): void {
    $old = AccountProvisionedGrant::factory()->for($this->account)->for($this->device)->claimed()->create();
    $reserve = $state === 'foreign'
        ? AccountReserveToken::factory()->create()
        : AccountReserveToken::factory()->for($this->account)->{$state}()->create();

    expect(fn () => $this->service->provisionFromReserve($this->user, $this->account, $this->device, $reserve->id))
        ->toThrow(ReserveTokenUnavailableException::class);
    expect(AccountProvisionedGrant::query()->count())->toBe(1)
        ->and($old->fresh()->status)->toBe(GrantStatus::Claimed);
})->with([
    'already used' => ['used'],
    'expired' => ['expired'],
    'discarded' => ['discarded'],
    'another account\'s token' => ['foreign'],
]);
