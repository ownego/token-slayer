<?php

use App\Enums\GrantStatus;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\RelationManagers\ProvisionsRelationManager;
use App\Models\Account;
use App\Models\AccountProvisionedGrant;
use App\Models\Device;
use App\Models\User;
use App\Support\CacheKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('lists one row per grant with its device fingerprint', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $user = User::factory()->create();
    $a = Device::factory()->for($user)->create(['device_id' => 'fp-machine-a']);
    $b = Device::factory()->for($user)->create(['device_id' => 'fp-machine-b']);
    AccountProvisionedGrant::factory()->for($account)->for($a)->claimed()->create();
    AccountProvisionedGrant::factory()->for($account)->for($b)->pending()->create();

    Livewire::actingAs($admin)
        ->test(ProvisionsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->assertCanSeeTableRecords($account->provisionedGrants)
        ->assertSee('fp-machine-a')
        ->assertSee('fp-machine-b');
});

it('add device with New device creates a placeholder holding a pending grant', function () {
    fakeAnthropic();
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create(['email' => 'ongtung2212002@gmail.com']);
    $user = User::factory()->create();
    Device::factory()->for($user)->create(['device_id' => 'fp-existing']);
    $account->users()->syncWithoutDetaching([$user->id => ['status' => 'tracked']]);

    Livewire::actingAs($admin)
        ->test(ProvisionsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->mountAction('addDevice')
        ->setActionData(['user_id' => $user->id, 'device_pk' => null])
        ->callMountedAction()
        ->assertActionMounted('confirmAddDevice')
        ->setActionData(['code' => 'pasted-code'])
        ->callMountedAction()
        ->assertNotified();

    $placeholder = $user->devices()->whereNull('device_id')->firstOrFail();
    $grant = AccountProvisionedGrant::query()
        ->where('account_id', $account->id)->where('device_id', $placeholder->id)->firstOrFail();
    expect($grant->status)->toBe(GrantStatus::Pending)
        ->and(Cache::get(CacheKeys::provisionedGrant($grant->id)))->not->toBeNull();
});

it('add device with a name creates a named placeholder', function () {
    fakeAnthropic();
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create(['email' => 'ongtung2212002@gmail.com']);
    $user = User::factory()->create();
    $account->users()->syncWithoutDetaching([$user->id => ['status' => 'tracked']]);

    Livewire::actingAs($admin)
        ->test(ProvisionsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->mountAction('addDevice')
        ->setActionData(['user_id' => $user->id, 'device_pk' => null, 'device_name' => 'Alice Laptop'])
        ->callMountedAction()
        ->assertActionMounted('confirmAddDevice')
        ->setActionData(['code' => 'pasted-code'])
        ->callMountedAction()
        ->assertNotified();

    $placeholder = $user->devices()->whereNull('device_id')->firstOrFail();
    expect($placeholder->name)->toBe('Alice Laptop');
});

it('shows the device name in the Device column with the fingerprint surfaced as a description', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $user = User::factory()->create();
    $device = Device::factory()->for($user)->create(['device_id' => 'fp-machine-a', 'name' => 'Alice Laptop']);
    AccountProvisionedGrant::factory()->for($account)->for($device)->claimed()->create();

    Livewire::actingAs($admin)
        ->test(ProvisionsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->assertSee('Alice Laptop')
        ->assertSee('fp-machine-a');
});

it('blocks opening a second placeholder while one is still awaiting a machine', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $user = User::factory()->create();
    Device::factory()->for($user)->placeholder()->create();
    $account->users()->syncWithoutDetaching([$user->id => ['status' => 'tracked']]);

    Livewire::actingAs($admin)
        ->test(ProvisionsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->mountAction('addDevice')
        ->setActionData(['user_id' => $user->id, 'device_pk' => null])
        ->callMountedAction()
        ->assertNotified()                       // the danger "already awaiting" notification
        ->assertActionNotMounted('confirmAddDevice');

    expect($user->devices()->whereNull('device_id')->count())->toBe(1);
});

it('a failed add-device provision (bad/expired code) leaves no orphan placeholder device or grant', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $user = User::factory()->create();
    $account->users()->syncWithoutDetaching([$user->id => ['status' => 'tracked']]);

    // An unstarted/expired state: exchangeVerifiedToken() throws
    // connect_state_expired before ever touching the network, so this
    // exercises the rollback without needing fakeAnthropic().
    Livewire::actingAs($admin)
        ->test(ProvisionsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->mountAction('confirmAddDevice', [
            'userId' => $user->id,
            'devicePk' => null,
            'authorizeUrl' => 'https://example.test/authorize',
            'state' => 'never-started-state',
        ])
        ->setActionData(['code' => 'bad-code'])
        ->callMountedAction()
        ->assertNotified();

    expect($user->devices()->count())->toBe(0)
        ->and(AccountProvisionedGrant::query()->count())->toBe(0);
});

it('reissue revokes the old grant and mints a pending replacement on the same device', function () {
    fakeAnthropic();
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create(['email' => 'ongtung2212002@gmail.com']);
    $user = User::factory()->create();
    $device = Device::factory()->for($user)->create(['device_id' => 'fp-broken']);
    $old = AccountProvisionedGrant::factory()->for($account)->for($device)->claimed()->create();
    Cache::put(CacheKeys::provisionedGrant($old->id), 'stale', 60);

    Livewire::actingAs($admin)
        ->test(ProvisionsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->mountTableAction('reissue', record: $old)
        ->callMountedAction()
        ->assertActionMounted('confirmReissue')
        ->setActionData(['code' => 'pasted-code'])
        ->callMountedAction()
        ->assertNotified();

    expect($old->fresh()->status)->toBe(GrantStatus::Revoked)
        ->and(Cache::get(CacheKeys::provisionedGrant($old->id)))->toBeNull();
    $new = AccountProvisionedGrant::query()->live()
        ->where('account_id', $account->id)->where('device_id', $device->id)->firstOrFail();
    expect($new->status)->toBe(GrantStatus::Pending);
});

it('revoke marks the grant revoked and hides the action on revoked rows', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $grant = AccountProvisionedGrant::factory()->for($account)->pending()->create();
    Cache::put(CacheKeys::provisionedGrant($grant->id), 'secret', 60);

    Livewire::actingAs($admin)
        ->test(ProvisionsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->callTableAction('revoke', record: $grant)
        ->assertNotified()
        ->assertTableActionHidden('revoke', record: $grant->fresh());

    expect($grant->fresh()->status)->toBe(GrantStatus::Revoked)
        ->and(Cache::get(CacheKeys::provisionedGrant($grant->id)))->toBeNull();
});

it('delete device removes a fully-revoked device row and its grants', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $user = User::factory()->create();
    $device = Device::factory()->for($user)->create(['device_id' => 'fp-wiped']);
    $grant = AccountProvisionedGrant::factory()->for($account)->for($device)->revoked()->create();

    Livewire::actingAs($admin)
        ->test(ProvisionsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->callTableAction('deleteDevice', record: $grant)
        ->assertNotified();

    expect(Device::query()->find($device->id))->toBeNull()
        ->and(AccountProvisionedGrant::query()->find($grant->id))->toBeNull();
});
