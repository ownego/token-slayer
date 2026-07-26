<?php

use App\Enums\GrantStatus;
use App\Models\Account;
use App\Models\AccountProvisionedGrant;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('casts status and belongs to an account and a device', function () {
    $grant = AccountProvisionedGrant::factory()->pending()->create();

    expect($grant->status)->toBe(GrantStatus::Pending)
        ->and($grant->account)->toBeInstanceOf(Account::class)
        ->and($grant->device)->toBeInstanceOf(Device::class)
        ->and($grant->provisioned_at)->not->toBeNull();
});

it('scopes live() to non-revoked grants', function () {
    $device = Device::factory()->create();
    AccountProvisionedGrant::factory()->for($device)->pending()->create();
    AccountProvisionedGrant::factory()->for($device)->claimed()->create();
    AccountProvisionedGrant::factory()->for($device)->revoked()->create();

    expect(AccountProvisionedGrant::query()->live()->count())->toBe(2);
});

it('is reachable from the account side', function () {
    $grant = AccountProvisionedGrant::factory()->claimed()->create();

    expect($grant->account->provisionedGrants)->toHaveCount(1);
});
