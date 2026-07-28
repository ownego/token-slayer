<?php

use App\Enums\GrantStatus;
use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\AccountProvisionedGrant;
use App\Models\Device;
use App\Models\User;
use App\Services\AccountProvisioningService;
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

it('drops a device from the summary once its removal is confirmed and its grant revoked', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['organization_uuid' => 'org-summary']);
    $user->accounts()->syncWithoutDetaching([
        $account->id => ['status' => MembershipStatus::Untracked->value],
    ]);
    $keptDevice = Device::factory()->for($user)->create(['device_id' => 'fp-keep']);
    $removedDevice = Device::factory()->for($user)->create(['device_id' => 'fp-remove']);
    AccountProvisionedGrant::factory()->for($account)->for($keptDevice)->claimed()->create();
    $removedGrant = AccountProvisionedGrant::factory()->for($account)->for($removedDevice)->claimed()->create();

    expect(AccountProvisionedGrant::deviceSummaryFor($account->id, $user->id))->toBe('2/2 set up');

    app(AccountProvisioningService::class)->confirmSetup($user, [], ['org-summary'], $removedDevice);

    expect($removedGrant->fresh()->status)->toBe(GrantStatus::Revoked)
        ->and(AccountProvisionedGrant::deviceSummaryFor($account->id, $user->id))->toBe('1/1 set up');
});
