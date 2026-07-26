<?php

use App\Enums\GrantStatus;
use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\AccountProvisionedGrant;
use App\Models\AccountUser;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('flips a pending provisioned membership to tracked when the user holds a live grant', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'HOOKTOK')]);
    $account = Account::factory()->create(['organization_uuid' => '11111111-1111-4111-8111-111111111111']);
    $device = Device::factory()->for($user)->create();
    AccountProvisionedGrant::factory()->for($account)->for($device)->claimed()->create();
    $user->accounts()->syncWithoutDetaching([
        $account->id => ['status' => MembershipStatus::Pending->value],
    ]);

    $res = $this->withHeader('Authorization', 'Bearer HOOKTOK')
        ->postJson('/api/provisioned/confirm', ['accounts' => [['org_uuid' => '11111111-1111-4111-8111-111111111111']]]);

    $res->assertOk()->assertJson(['confirmed' => 1]);

    $pivot = AccountUser::query()
        ->where('user_id', $user->id)->where('account_id', $account->id)->firstOrFail();
    expect($pivot->status)->toBe(MembershipStatus::Tracked);
});

it('skips an org uuid the user has no provisioned pivot for, without creating a membership', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'HOOKTOK')]);
    $account = Account::factory()->create(['organization_uuid' => '22222222-2222-4222-8222-222222222222']);

    expect(AccountUser::query()->where('user_id', $user->id)->where('account_id', $account->id)->exists())
        ->toBeFalse();

    $res = $this->withHeader('Authorization', 'Bearer HOOKTOK')
        ->postJson('/api/provisioned/confirm', ['accounts' => [['org_uuid' => '22222222-2222-4222-8222-222222222222']]]);

    $res->assertOk()->assertJson(['confirmed' => 0]);

    expect(AccountUser::query()->where('user_id', $user->id)->where('account_id', $account->id)->exists())
        ->toBeFalse();
});

it('skips an org uuid where the user has a pivot that was never provisioned (self-graft attempt)', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'HOOKTOK')]);
    $account = Account::factory()->create(['organization_uuid' => '88888888-8888-4888-8888-888888888888']);
    $user->accounts()->syncWithoutDetaching([
        $account->id => ['status' => MembershipStatus::Untracked->value],
    ]);

    $res = $this->withHeader('Authorization', 'Bearer HOOKTOK')
        ->postJson('/api/provisioned/confirm', ['accounts' => [['org_uuid' => '88888888-8888-4888-8888-888888888888']]]);

    $res->assertOk()->assertJson(['confirmed' => 0]);

    $pivot = AccountUser::query()
        ->where('user_id', $user->id)->where('account_id', $account->id)->firstOrFail();
    expect($pivot->status)->toBe(MembershipStatus::Untracked);
});

it('ignores an unknown org uuid without creating an account or erroring', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'HOOKTOK')]);

    $unknownOrgUuid = '99999999-9999-4999-8999-999999999999';
    $res = $this->withHeader('Authorization', 'Bearer HOOKTOK')
        ->postJson('/api/provisioned/confirm', ['accounts' => [['org_uuid' => $unknownOrgUuid]]]);

    $res->assertOk()->assertJson(['confirmed' => 0]);
    expect(Account::query()->where('organization_uuid', $unknownOrgUuid)->exists())->toBeFalse();
});

it('does not touch a membership on a different account absent from the list (additive only)', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'HOOKTOK')]);
    $confirmedAccount = Account::factory()->create(['organization_uuid' => '33333333-3333-4333-8333-333333333333']);
    $untouchedAccount = Account::factory()->create(['organization_uuid' => '44444444-4444-4444-8444-444444444444']);
    $device = Device::factory()->for($user)->create();
    AccountProvisionedGrant::factory()->for($confirmedAccount)->for($device)->claimed()->create();

    $user->accounts()->syncWithoutDetaching([
        $confirmedAccount->id => ['status' => MembershipStatus::Pending->value],
        $untouchedAccount->id => ['status' => MembershipStatus::Untracked->value],
    ]);

    $this->withHeader('Authorization', 'Bearer HOOKTOK')
        ->postJson('/api/provisioned/confirm', ['accounts' => [['org_uuid' => '33333333-3333-4333-8333-333333333333']]])
        ->assertOk()->assertJson(['confirmed' => 1]);

    $untouched = AccountUser::query()
        ->where('user_id', $user->id)->where('account_id', $untouchedAccount->id)->firstOrFail();
    expect($untouched->status)->toBe(MembershipStatus::Untracked)
        // Grant-layer equivalent of "additive only": confirming the OTHER
        // account must not write any grant (claimed or otherwise) for this one.
        ->and(AccountProvisionedGrant::query()->where('account_id', $untouchedAccount->id)->exists())->toBeFalse();
});

it('confirms only the provisioned org in a multi-org batch and ignores the unknown one', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'HOOKTOK')]);
    $knownAccount = Account::factory()->create(['organization_uuid' => '55555555-5555-4555-8555-555555555555']);
    $device = Device::factory()->for($user)->create();
    AccountProvisionedGrant::factory()->for($knownAccount)->for($device)->claimed()->create();
    $user->accounts()->syncWithoutDetaching([
        $knownAccount->id => ['status' => MembershipStatus::Pending->value],
    ]);
    $unknownOrgUuid = '66666666-6666-4666-8666-666666666666';

    $res = $this->withHeader('Authorization', 'Bearer HOOKTOK')
        ->postJson('/api/provisioned/confirm', ['accounts' => [
            ['org_uuid' => '55555555-5555-4555-8555-555555555555'],
            ['org_uuid' => $unknownOrgUuid],
        ]]);

    $res->assertOk()->assertJson(['confirmed' => 1]);

    $pivot = AccountUser::query()
        ->where('user_id', $user->id)->where('account_id', $knownAccount->id)->firstOrFail();
    expect($pivot->status)->toBe(MembershipStatus::Tracked);
    expect(Account::query()->where('organization_uuid', $unknownOrgUuid)->exists())->toBeFalse();
});

it('dedupes a repeated org uuid so it only counts once', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'HOOKTOK')]);
    $account = Account::factory()->create(['organization_uuid' => '10101010-1010-4010-8010-101010101010']);
    $device = Device::factory()->for($user)->create();
    AccountProvisionedGrant::factory()->for($account)->for($device)->claimed()->create();
    $user->accounts()->syncWithoutDetaching([
        $account->id => ['status' => MembershipStatus::Pending->value],
    ]);

    $res = $this->withHeader('Authorization', 'Bearer HOOKTOK')
        ->postJson('/api/provisioned/confirm', ['accounts' => [
            ['org_uuid' => '10101010-1010-4010-8010-101010101010'],
            ['org_uuid' => '10101010-1010-4010-8010-101010101010'],
        ]]);

    $res->assertOk()->assertJson(['confirmed' => 1]);
});

it('leaves a device grant\'s claimed_at unchanged when set_up is reconfirmed', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'HOOKTOK')]);
    $account = Account::factory()->create(['organization_uuid' => '77777777-7777-4777-8777-777777777777']);
    $device = Device::factory()->for($user)->create();
    $claimedAt = now()->subHour()->startOfSecond();
    $grant = AccountProvisionedGrant::factory()->for($account)->for($device)->create([
        'status' => GrantStatus::Claimed,
        'claimed_at' => $claimedAt,
    ]);
    $user->accounts()->syncWithoutDetaching([
        $account->id => [
            'status' => MembershipStatus::Pending->value,
        ],
    ]);

    $this->withHeader('Authorization', 'Bearer HOOKTOK')
        ->postJson('/api/provisioned/confirm', ['accounts' => [['org_uuid' => '77777777-7777-4777-8777-777777777777']]])
        ->assertOk()->assertJson(['confirmed' => 1]);

    $pivot = AccountUser::query()
        ->where('user_id', $user->id)->where('account_id', $account->id)->firstOrFail();
    expect($pivot->status)->toBe(MembershipStatus::Tracked)
        ->and($grant->fresh()->claimed_at->eq($claimedAt))->toBeTrue();
});

it('rejects an unauthenticated request with no hook token', function () {
    $this->postJson('/api/provisioned/confirm', ['accounts' => [['org_uuid' => '11111111-1111-4111-8111-111111111111']]])
        ->assertStatus(401);
});

it('rejects a malformed body with a 422', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'HOOKTOK')]);

    $this->withHeader('Authorization', 'Bearer HOOKTOK')
        ->postJson('/api/provisioned/confirm', ['accounts' => 'not-an-array'])
        ->assertStatus(422);

    $this->withHeader('Authorization', 'Bearer HOOKTOK')
        ->postJson('/api/provisioned/confirm', ['accounts' => [['org_uuid' => 'not-a-uuid']]])
        ->assertStatus(422);

    $this->withHeader('Authorization', 'Bearer HOOKTOK')
        ->postJson('/api/provisioned/confirm', [])
        ->assertStatus(422);
});

it('stamps deprovisioned_at on the calling device\'s grant for a removed untracked org', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'HOOKTOK')]);
    $account = Account::factory()->create(['organization_uuid' => '33333333-3333-4333-8333-333333333333']);
    $device = Device::factory()->for($user)->legacyDefault()->create();
    $user->accounts()->syncWithoutDetaching([
        $account->id => ['status' => MembershipStatus::Untracked->value],
    ]);

    $res = $this->withHeader('Authorization', 'Bearer HOOKTOK')->postJson('/api/provisioned/confirm', [
        'removed' => [['org_uuid' => '33333333-3333-4333-8333-333333333333']],
    ]);

    $res->assertOk()->assertJsonPath('deprovisioned', 1);

    $grant = AccountProvisionedGrant::query()
        ->where('account_id', $account->id)->where('device_id', $device->id)->firstOrFail();
    expect($grant->deprovisioned_at)->not->toBeNull();
});

it('stamps deprovisioned_at on the calling device\'s grant for an event-materialized untracked org (provisioned_at null)', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'HOOKTOK')]);
    $account = Account::factory()->create(['organization_uuid' => '20202020-2020-4020-8020-202020202020']);
    $device = Device::factory()->for($user)->legacyDefault()->create();
    $user->accounts()->syncWithoutDetaching([
        $account->id => ['status' => MembershipStatus::Untracked->value],
    ]);

    $res = $this->withHeader('Authorization', 'Bearer HOOKTOK')->postJson('/api/provisioned/confirm', [
        'removed' => [['org_uuid' => '20202020-2020-4020-8020-202020202020']],
    ]);

    $res->assertOk()->assertJsonPath('deprovisioned', 1);

    $grant = AccountProvisionedGrant::query()
        ->where('account_id', $account->id)->where('device_id', $device->id)->firstOrFail();
    expect($grant->deprovisioned_at)->not->toBeNull();
});

it('stamps nothing and counts zero when removing an org uuid the user holds no pivot for', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'HOOKTOK')]);
    $account = Account::factory()->create(['organization_uuid' => '30303030-3030-4030-8030-303030303030']);

    expect(AccountUser::query()->where('user_id', $user->id)->where('account_id', $account->id)->exists())
        ->toBeFalse();

    $res = $this->withHeader('Authorization', 'Bearer HOOKTOK')->postJson('/api/provisioned/confirm', [
        'removed' => [['org_uuid' => '30303030-3030-4030-8030-303030303030']],
    ]);

    $res->assertOk()->assertJsonPath('deprovisioned', 0);

    expect(AccountUser::query()->where('user_id', $user->id)->where('account_id', $account->id)->exists())
        ->toBeFalse();
});

it('stamps nothing and counts zero when confirming a removal for an org the user has no membership on', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'HOOKTOK')]);
    $account = Account::factory()->create(['organization_uuid' => '40404040-4040-4040-8040-404040404040']);
    // A resolvable device but NO account_user row at all — not even
    // Untracked — so a hook-token holder who merely knows an org uuid
    // cannot plant a tombstone grant on an account they aren't a member of.
    Device::factory()->for($user)->legacyDefault()->create();

    expect(AccountUser::query()->where('user_id', $user->id)->where('account_id', $account->id)->exists())
        ->toBeFalse();

    $res = $this->withHeader('Authorization', 'Bearer HOOKTOK')->postJson('/api/provisioned/confirm', [
        'removed' => [['org_uuid' => '40404040-4040-4040-8040-404040404040']],
    ]);

    $res->assertOk()->assertJsonPath('deprovisioned', 0);

    expect(AccountProvisionedGrant::query()->where('account_id', $account->id)->exists())->toBeFalse();
});

it('still accepts the legacy {accounts:[...]} body as set_up (old clients)', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'HOOKTOK')]);
    $account = Account::factory()->create(['organization_uuid' => '44444444-4444-4444-8444-444444444444']);
    $device = Device::factory()->for($user)->create();
    AccountProvisionedGrant::factory()->for($account)->for($device)->claimed()->create();
    $user->accounts()->syncWithoutDetaching([
        $account->id => ['status' => MembershipStatus::Pending->value],
    ]);

    $res = $this->withHeader('Authorization', 'Bearer HOOKTOK')->postJson('/api/provisioned/confirm', [
        'accounts' => [['org_uuid' => '44444444-4444-4444-8444-444444444444']],
    ]);

    $res->assertOk()->assertJsonPath('confirmed', 1);
    $pivot = AccountUser::query()->where('user_id', $user->id)->where('account_id', $account->id)->firstOrFail();
    expect($pivot->status)->toBe(MembershipStatus::Tracked);
});

it('stamps a removal against the calling device only', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'HOOKTOK')]);
    $account = Account::factory()->create(['organization_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']);
    $user->accounts()->syncWithoutDetaching([$account->id => ['status' => MembershipStatus::Untracked->value]]);
    $deviceA = Device::factory()->for($user)->create(['device_id' => 'fp-a']);
    $deviceB = Device::factory()->for($user)->create(['device_id' => 'fp-b']);
    AccountProvisionedGrant::factory()->for($account)->for($deviceA)->claimed()->create();
    AccountProvisionedGrant::factory()->for($account)->for($deviceB)->claimed()->create();

    $this->withHeader('Authorization', 'Bearer HOOKTOK')
        ->postJson('/api/provisioned/confirm', [
            'removed' => [['org_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']],
            'device_id' => 'fp-a',
        ])
        ->assertOk()->assertJsonPath('deprovisioned', 1);

    // Device B still gets the instruction on its next pull.
    $this->withHeader('Authorization', 'Bearer HOOKTOK')
        ->getJson('/api/provisioned?device_id=fp-b')
        ->assertOk()->assertJsonPath('remove.0.org_uuid', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
});

it('rejects an empty confirmation body with 422', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'HOOKTOK')]);

    $this->withHeader('Authorization', 'Bearer HOOKTOK')
        ->postJson('/api/provisioned/confirm', [])
        ->assertUnprocessable();
});
