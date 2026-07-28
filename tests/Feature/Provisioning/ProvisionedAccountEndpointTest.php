<?php

use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\AccountProvisionedGrant;
use App\Models\Device;
use App\Models\User;
use App\Support\CacheKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

// Seed a grant + its encrypted cache secret, exactly as provisionForDevice would.
function seedGrant(Device $device, Account $account, array $secret, string $state = 'pending'): AccountProvisionedGrant
{
    $grant = AccountProvisionedGrant::factory()->for($account)->for($device)->{$state}()->create();
    Cache::put(CacheKeys::provisionedGrant($grant->id), Crypt::encryptString(json_encode($secret)), 86400);

    return $grant;
}

it('serves the default device to an old client with no device_id', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'HOOKTOK')]);
    $device = Device::factory()->for($user)->legacyDefault()->create();
    $account = Account::factory()->create(['email' => 's@org.com', 'organization_uuid' => 'org-1']);
    seedGrant($device, $account, [
        'name' => 's@org.com', 'email' => 's@org.com', 'org_uuid' => 'org-1',
        'access_token' => 'AT', 'refresh_token' => 'RT', 'expires_at' => 1,
    ]);
    $user->accounts()->syncWithoutDetaching([$account->id => ['status' => MembershipStatus::Tracked->value]]);

    $this->withHeader('Authorization', 'Bearer HOOKTOK')->getJson('/api/provisioned')
        ->assertOk()
        ->assertJsonPath('accounts.0.access_token', 'AT')
        ->assertJsonPath('memberships.0.org_uuid', 'org-1')
        ->assertJsonPath('remove', []);
});

it('binds a new fingerprint to an open placeholder and serves its grant', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'HOOKTOK')]);
    $placeholder = Device::factory()->for($user)->placeholder()->create();
    $account = Account::factory()->create(['organization_uuid' => 'org-b']);
    seedGrant($placeholder, $account, ['name' => 'b', 'email' => 'b', 'org_uuid' => 'org-b',
        'access_token' => 'AT-B', 'refresh_token' => 'RT-B', 'expires_at' => 1]);

    $this->withHeader('Authorization', 'Bearer HOOKTOK')
        ->getJson('/api/provisioned?device_id=fp-machine-b')
        ->assertOk()->assertJsonPath('accounts.0.access_token', 'AT-B');

    expect($placeholder->fresh()->device_id)->toBe('fp-machine-b');
});

it('serves nothing to an unknown fingerprint with no open door', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'HOOKTOK')]);
    Device::factory()->for($user)->create(['device_id' => 'fp-taken']);

    $this->withHeader('Authorization', 'Bearer HOOKTOK')
        ->getJson('/api/provisioned?device_id=fp-stranger')
        ->assertOk()->assertJsonCount(0, 'accounts');
});

it('returns the authed user\'s grants from cache, idempotently while the cache lives', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'HOOKTOK')]);
    $device = Device::factory()->for($user)->legacyDefault()->create();
    $account = Account::factory()->create(['email' => 'shared@org.com', 'organization_uuid' => 'org-1']);
    $grant = seedGrant($device, $account, [
        'name' => 'shared@org.com', 'email' => 'shared@org.com', 'org_uuid' => 'org-1',
        'access_token' => 'sk-ant-oat01-ACCESS', 'refresh_token' => 'sk-ant-ort01-REFRESH',
        'expires_at' => 1_800_000_000,
    ]);

    $res = $this->withHeader('Authorization', 'Bearer HOOKTOK')->getJson('/api/provisioned');
    $res->assertOk()
        ->assertJsonPath('accounts.0.email', 'shared@org.com')
        ->assertJsonPath('accounts.0.org_uuid', 'org-1')
        ->assertJsonPath('accounts.0.access_token', 'sk-ant-oat01-ACCESS')
        ->assertJsonPath('accounts.0.refresh_token', 'sk-ant-ort01-REFRESH')
        ->assertJsonPath('accounts.0.expires_at', 1_800_000_000);

    // First pull marks the grant claimed.
    expect($grant->fresh()->claimed_at)->not->toBeNull();

    // Idempotent: a second pull STILL returns it (the cache secret is not consumed).
    $this->withHeader('Authorization', 'Bearer HOOKTOK')->getJson('/api/provisioned')
        ->assertOk()->assertJsonCount(1, 'accounts')
        ->assertJsonPath('accounts.0.access_token', 'sk-ant-oat01-ACCESS');

    // Once the cache secret is gone (24h TTL elapsed / revoked), it is no longer served.
    Cache::forget(CacheKeys::provisionedGrant($grant->id));
    $this->withHeader('Authorization', 'Bearer HOOKTOK')->getJson('/api/provisioned')
        ->assertOk()->assertJsonCount(0, 'accounts');
});

it('excludes another user\'s, a revoked, and an expired-cache grant', function () {
    $me = User::factory()->create(['hook_token' => hash('sha256', 'MINE')]);
    $myDevice = Device::factory()->for($me)->legacyDefault()->create();
    $other = User::factory()->create();
    $otherDevice = Device::factory()->for($other)->legacyDefault()->create();

    seedGrant($otherDevice, Account::factory()->create(), ['name' => 'x', 'email' => 'x', 'org_uuid' => null,
        'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => 1]);                                       // not mine
    AccountProvisionedGrant::factory()->for(Account::factory()->create())->for($myDevice)->revoked()->create();  // revoked (+ no cache)
    AccountProvisionedGrant::factory()->for(Account::factory()->create())->for($myDevice)->pending()->create();  // provisioned but cache expired (no secret)

    $this->withHeader('Authorization', 'Bearer MINE')->getJson('/api/provisioned')
        ->assertOk()->assertJsonCount(0, 'accounts');
});

it('returns memberships (tracked) and remove (untracked) alongside accounts', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'HOOKTOK')]);
    $device = Device::factory()->for($user)->legacyDefault()->create();
    $pending = Account::factory()->create(['email' => 'p@org.com', 'organization_uuid' => 'org-pending']);
    $tracked = Account::factory()->create(['organization_uuid' => 'org-tracked']);
    $untracked = Account::factory()->create(['organization_uuid' => 'org-untracked']);

    seedGrant($device, $pending, [
        'name' => 'p@org.com', 'email' => 'p@org.com', 'org_uuid' => 'org-pending',
        'access_token' => 'sk-ant-oat01-A', 'refresh_token' => 'sk-ant-ort01-R', 'expires_at' => 1_800_000_000,
    ]);
    $user->accounts()->syncWithoutDetaching([$tracked->id => ['status' => MembershipStatus::Tracked->value]]);
    $user->accounts()->syncWithoutDetaching([$untracked->id => ['status' => MembershipStatus::Untracked->value]]);

    $res = $this->withHeader('Authorization', 'Bearer HOOKTOK')->getJson('/api/provisioned');

    $res->assertOk()
        ->assertJsonPath('accounts.0.org_uuid', 'org-pending')
        ->assertJsonPath('memberships.0.org_uuid', 'org-tracked')
        ->assertJsonPath('remove.0.org_uuid', 'org-untracked');
});
