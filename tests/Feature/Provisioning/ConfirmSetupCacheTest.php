<?php

use App\Models\Account;
use App\Models\AccountProvisionedGrant;
use App\Models\Device;
use App\Models\User;
use App\Services\AccountProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('clears the pending-secret fields immediately when a setup confirmation succeeds', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['organization_uuid' => 'org-cache-y']);
    $device = Device::factory()->for($user)->create(['device_id' => 'fp-cache-y']);
    $grant = AccountProvisionedGrant::factory()->for($account)->for($device)->claimed()->create([
        'pending_claude_access_token' => 'encrypted-secret-placeholder',
    ]);

    $service = app(AccountProvisioningService::class);
    $result = $service->confirmSetup($user, ['org-cache-y'], [], $device);

    expect($result['confirmed'])->toBe(1)
        ->and($grant->fresh()->pending_claude_access_token)->toBeNull();
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
