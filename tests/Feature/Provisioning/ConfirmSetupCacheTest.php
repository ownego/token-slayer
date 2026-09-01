<?php

use App\Models\Account;
use App\Models\AccountProvisionedGrant;
use App\Models\Device;
use App\Models\User;
use App\Services\AccountProvisioningService;
use App\Support\CacheKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

it('forgets the cached grant secret immediately when a setup confirmation succeeds', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['organization_uuid' => 'org-cache-y']);
    $device = Device::factory()->for($user)->create(['device_id' => 'fp-cache-y']);
    $grant = AccountProvisionedGrant::factory()->for($account)->for($device)->claimed()->create();
    Cache::put(CacheKeys::provisionedGrant($grant->id), 'encrypted-secret-placeholder', now()->addDay());

    $service = app(AccountProvisioningService::class);
    $result = $service->confirmSetup($user, ['org-cache-y'], [], $device);

    expect($result['confirmed'])->toBe(1)
        ->and(Cache::get(CacheKeys::provisionedGrant($grant->id)))->toBeNull();
});

it('does not error when the device is null (old CLI without a fingerprint)', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['organization_uuid' => 'org-cache-z']);
    $device = Device::factory()->for($user)->create(['device_id' => 'fp-cache-z']);
    AccountProvisionedGrant::factory()->for($account)->for($device)->claimed()->create();

    $service = app(AccountProvisioningService::class);
    $result = $service->confirmSetup($user, ['org-cache-z'], [], null);

    expect($result['confirmed'])->toBe(1);
});
