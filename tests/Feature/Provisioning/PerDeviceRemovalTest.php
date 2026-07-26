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

function untrackedMembership(User $user, Account $account): void
{
    $user->accounts()->syncWithoutDetaching([
        $account->id => ['status' => MembershipStatus::Untracked->value],
    ]);
}

it('tells each device to remove an untracked org until THAT device confirms', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['organization_uuid' => 'org-x']);
    untrackedMembership($user, $account);
    $deviceA = Device::factory()->for($user)->create(['device_id' => 'fp-a']);
    $deviceB = Device::factory()->for($user)->create(['device_id' => 'fp-b']);
    AccountProvisionedGrant::factory()->for($account)->for($deviceA)->claimed()->create();
    AccountProvisionedGrant::factory()->for($account)->for($deviceB)->claimed()->create();

    $service = app(AccountProvisioningService::class);

    expect($service->removable($user, $deviceA))->toBe([['org_uuid' => 'org-x']])
        ->and($service->removable($user, $deviceB))->toBe([['org_uuid' => 'org-x']]);

    $service->confirmSetup($user, [], ['org-x'], $deviceA);

    // A confirmed; B still gets the instruction.
    expect($service->removable($user, $deviceA))->toBe([])
        ->and($service->removable($user, $deviceB))->toBe([['org_uuid' => 'org-x']]);
});

it('creates a Revoked tombstone when a device with no grant confirms a removal', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['organization_uuid' => 'org-e']);
    untrackedMembership($user, $account);   // event-materialized: no grant anywhere
    $device = Device::factory()->for($user)->create(['device_id' => 'fp-a']);

    $service = app(AccountProvisioningService::class);
    expect($service->removable($user, $device))->toBe([['org_uuid' => 'org-e']]);

    $result = $service->confirmSetup($user, [], ['org-e'], $device);

    $tombstone = AccountProvisionedGrant::query()
        ->where('account_id', $account->id)->where('device_id', $device->id)->first();
    expect($result['deprovisioned'])->toBe(1)
        ->and($tombstone->status)->toBe(GrantStatus::Revoked)
        ->and($tombstone->deprovisioned_at)->not->toBeNull()
        ->and($service->removable($user, $device))->toBe([]);
});

it('returns no removals and stamps nothing without a resolved device', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['organization_uuid' => 'org-x']);
    untrackedMembership($user, $account);

    $service = app(AccountProvisioningService::class);

    expect($service->removable($user, null))->toBe([])
        ->and($service->confirmSetup($user, [], ['org-x'], null)['deprovisioned'])->toBe(0);
});

it('guards set_up promotion on holding a live grant via any of the user devices', function () {
    $user = User::factory()->create();
    $granted = Account::factory()->create(['organization_uuid' => 'org-g']);
    $ungranted = Account::factory()->create(['organization_uuid' => 'org-u']);
    $device = Device::factory()->for($user)->create();
    AccountProvisionedGrant::factory()->for($granted)->for($device)->claimed()->create();
    $user->accounts()->syncWithoutDetaching([
        $granted->id => ['status' => MembershipStatus::Pending->value],
        $ungranted->id => ['status' => MembershipStatus::Pending->value],
    ]);

    $result = app(AccountProvisioningService::class)
        ->confirmSetup($user, ['org-g', 'org-u'], [], $device);

    expect($result['confirmed'])->toBe(1)
        ->and($user->accounts()->find($granted->id)->pivot->status)->toBe(MembershipStatus::Tracked)
        ->and($user->accounts()->find($ungranted->id)->pivot->status)->toBe(MembershipStatus::Pending);
});
