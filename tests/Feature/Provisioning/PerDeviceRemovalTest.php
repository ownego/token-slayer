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

it('confirm stamps the newest grant row, not an older revoked one from a prior reissue', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['organization_uuid' => 'org-newest']);
    $device = Device::factory()->for($user)->create(['device_id' => 'fp-a']);
    $stale = AccountProvisionedGrant::factory()->for($account)->for($device)->revoked()->create();
    $newest = AccountProvisionedGrant::factory()->for($account)->for($device)->claimed()->create();
    untrackedMembership($user, $account);

    $service = app(AccountProvisioningService::class);
    $service->confirmSetup($user, [], ['org-newest'], $device);

    expect($stale->fresh()->deprovisioned_at)->toBeNull()
        ->and($newest->fresh()->deprovisioned_at)->not->toBeNull();
});

it('returns every untracked org but stamps nothing without a resolved device', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['organization_uuid' => 'org-x']);
    untrackedMembership($user, $account);

    $service = app(AccountProvisioningService::class);

    expect($service->removable($user, null))->toBe([['org_uuid' => 'org-x']])
        ->and($service->confirmSetup($user, [], ['org-x'], null)['deprovisioned'])->toBe(0);
});

it('still returns the broadcast for a user with a device row when this request resolved no device', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['organization_uuid' => 'org-y']);
    untrackedMembership($user, $account);
    Device::factory()->for($user)->create(['device_id' => 'fp-known']);

    $service = app(AccountProvisioningService::class);

    // The condition is per-request ("this request resolved no device"), not
    // "user has zero devices" — an unresolved fingerprint from an unknown
    // machine still gets the removal broadcast even though the user already
    // has a device row on file.
    expect($service->removable($user, null))->toBe([['org_uuid' => 'org-y']]);
});

it('excludes only the org confirmed by the newest grant on the device, not a stale revoked one', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['organization_uuid' => 'org-r']);
    $device = Device::factory()->for($user)->create(['device_id' => 'fp-a']);
    untrackedMembership($user, $account);

    $service = app(AccountProvisioningService::class);

    // First confirm: device notes removal of org-r on its (only) grant.
    $service->confirmSetup($user, [], ['org-r'], $device);
    expect($service->removable($user, $device))->toBe([]);

    // Admin re-provisions org-r onto the same device: the stale grant is
    // revoked and a fresh, clean grant is created — reopening the slot.
    $account->provisionedGrants()->create([
        'device_id' => $device->id,
        'status' => GrantStatus::Claimed,
        'provisioned_at' => now(),
    ]);
    untrackedMembership($user, $account);

    // The stale revoked grant still carries the old deprovisioned_at stamp,
    // but only the newest grant's stamp should count.
    expect($service->removable($user, $device))->toBe([['org_uuid' => 'org-r']]);
});

it('reissued-then-unverified device receives the removal instruction again', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['organization_uuid' => 'org-reissue']);
    $device = Device::factory()->for($user)->create(['device_id' => 'fp-a']);
    $grant = AccountProvisionedGrant::factory()->for($account)->for($device)->claimed()->create();
    $user->accounts()->syncWithoutDetaching([
        $account->id => ['status' => MembershipStatus::Tracked->value],
    ]);

    $service = app(AccountProvisioningService::class);

    // Reissue: old grant revoked, fresh Pending grant minted on the same device.
    $service->revoke($grant);
    $account->provisionedGrants()->create([
        'device_id' => $device->id,
        'status' => GrantStatus::Pending,
        'provisioned_at' => now(),
    ]);

    // Admin unverifies the member.
    $user->accounts()->syncWithoutDetaching([
        $account->id => ['status' => MembershipStatus::Untracked->value],
    ]);

    expect($service->removable($user, $device))->toBe([['org_uuid' => 'org-reissue']]);
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
