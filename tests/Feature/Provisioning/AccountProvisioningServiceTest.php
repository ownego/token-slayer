<?php

use App\Enums\GrantStatus;
use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\AccountProvisionedGrant;
use App\Models\Device;
use App\Models\User;
use App\Services\AccountConnectService;
use App\Services\AccountProvisioningService;
use App\Support\CacheKeys;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

// Fake the PKCE exchange so no HTTP leaves the test.
function fakeExchange(): void
{
    $mock = Mockery::mock(AccountConnectService::class);
    $mock->shouldReceive('exchangeVerifiedToken')->andReturn([
        'access_token' => 'sk-ant-oat01-NEW',
        'refresh_token' => 'sk-ant-ort01-NEW',
        'expires_in' => 28800,
        'token_uuid' => 'tok-uuid-1',
    ]);
    app()->instance(AccountConnectService::class, $mock);
}

it('creates a placeholder as the provision target when no device is selected', function () {
    $user = User::factory()->create();

    $device = app(AccountProvisioningService::class)->resolveProvisionTarget($user, null);

    expect($device->device_id)->toBeNull()
        ->and($user->devices()->count())->toBe(1);
});

it('never mints a default device — default exists only from migration backfill', function () {
    $user = User::factory()->create();

    app(AccountProvisioningService::class)->resolveProvisionTarget($user, null);

    expect($user->devices()->where('device_id', Device::DEFAULT_NAME)->exists())->toBeFalse();
});

it('returns the selected device by id, scoped to the user', function () {
    $user = User::factory()->create();
    $mine = Device::factory()->for($user)->create();
    $theirs = Device::factory()->create();

    $service = app(AccountProvisioningService::class);

    expect($service->resolveProvisionTarget($user, $mine->id)->id)->toBe($mine->id);
    $service->resolveProvisionTarget($user, $theirs->id);
})->throws(ModelNotFoundException::class);

it('provisions a Pending grant on the device, caches the secret, and tracks membership', function () {
    fakeExchange();
    $user = User::factory()->create();
    $account = Account::factory()->create(['organization_uuid' => 'org-1']);
    $device = Device::factory()->for($user)->legacyDefault()->create();

    $grant = app(AccountProvisioningService::class)
        ->provisionForDevice($user, $account, $device, 'state', 'code#state');

    expect($grant->status)->toBe(GrantStatus::Pending)
        ->and($grant->device_id)->toBe($device->id)
        ->and($grant->token_uuid)->toBe('tok-uuid-1');

    $payload = json_decode(Crypt::decryptString(Cache::get(CacheKeys::provisionedGrant($grant->id))), true);
    expect($payload['access_token'])->toBe('sk-ant-oat01-NEW')
        ->and($payload['org_uuid'])->toBe('org-1');

    expect($user->accounts()->first()->pivot->status)->toBe(MembershipStatus::Tracked);
});

it('revokes the previous live grant on the same (account, device) when re-provisioning', function () {
    fakeExchange();
    $user = User::factory()->create();
    $account = Account::factory()->create();
    $device = Device::factory()->for($user)->create();
    $old = AccountProvisionedGrant::factory()->for($account)->for($device)->claimed()->create();
    Cache::put(CacheKeys::provisionedGrant($old->id), 'stale', 60);

    $new = app(AccountProvisioningService::class)
        ->provisionForDevice($user, $account, $device, 'state', 'code#state');

    expect($old->fresh()->status)->toBe(GrantStatus::Revoked)
        ->and(Cache::get(CacheKeys::provisionedGrant($old->id)))->toBeNull()
        ->and($new->status)->toBe(GrantStatus::Pending)
        ->and($account->provisionedGrants()->live()->where('device_id', $device->id)->count())->toBe(1);
});

it('revoke() marks the grant revoked and forgets its secret', function () {
    $grant = AccountProvisionedGrant::factory()->pending()->create();
    Cache::put(CacheKeys::provisionedGrant($grant->id), 'secret', 60);

    app(AccountProvisioningService::class)->revoke($grant);

    expect($grant->fresh()->status)->toBe(GrantStatus::Revoked)
        ->and($grant->fresh()->revoked_at)->not->toBeNull()
        ->and(Cache::get(CacheKeys::provisionedGrant($grant->id)))->toBeNull();
});

it('claims pending grants for the resolved device and marks them claimed', function () {
    $user = User::factory()->create();
    $device = Device::factory()->for($user)->legacyDefault()->create();
    $account = Account::factory()->create(['email' => 'a@org.com', 'organization_uuid' => 'org-a']);
    $grant = AccountProvisionedGrant::factory()->for($account)->for($device)->pending()->create();
    Cache::put(CacheKeys::provisionedGrant($grant->id), Crypt::encryptString(json_encode([
        'name' => 'a@org.com', 'email' => 'a@org.com', 'org_uuid' => 'org-a',
        'access_token' => 'AT', 'refresh_token' => 'RT', 'expires_at' => 1,
    ])), 86400);

    $payloads = app(AccountProvisioningService::class)->claim($user, null);

    expect($payloads)->toHaveCount(1)
        ->and($payloads[0]['access_token'])->toBe('AT')
        ->and($grant->fresh()->status)->toBe(GrantStatus::Claimed)
        ->and($grant->fresh()->claimed_at)->not->toBeNull();

    // Idempotent within the TTL: a second claim still serves it.
    expect(app(AccountProvisioningService::class)->claim($user, null))->toHaveCount(1);
});

it('serves nothing for a dead cache, a revoked grant, or another device', function () {
    $user = User::factory()->create();
    $mine = Device::factory()->for($user)->create(['device_id' => 'fp-mine']);
    $other = Device::factory()->for($user)->create(['device_id' => 'fp-other']);

    AccountProvisionedGrant::factory()->for($mine)->pending()->create();     // no cache
    $revoked = AccountProvisionedGrant::factory()->for($mine)->revoked()->create();
    Cache::put(CacheKeys::provisionedGrant($revoked->id), Crypt::encryptString('{}'), 60);
    $othersGrant = AccountProvisionedGrant::factory()->for($other)->pending()->create();
    Cache::put(CacheKeys::provisionedGrant($othersGrant->id), Crypt::encryptString('{}'), 60);

    expect(app(AccountProvisioningService::class)->claim($user, 'fp-mine'))->toBe([]);
});

it('claims nothing when no device resolves', function () {
    $user = User::factory()->create();

    expect(app(AccountProvisioningService::class)->claim($user, null))->toBe([])
        ->and(app(AccountProvisioningService::class)->claim($user, 'fp-stranger'))->toBe([]);
});
