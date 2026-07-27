<?php

use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\RelationManagers\DevicesRelationManager;
use App\Models\Account;
use App\Models\AccountProvisionedGrant;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('lists a user\'s devices with name precedence and grant counts', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $account = Account::factory()->create();
    $named = Device::factory()->for($user)->create(['device_id' => 'fp-named', 'name' => 'Alice Laptop']);
    $unnamed = Device::factory()->for($user)->create(['device_id' => 'fp-unnamed', 'name' => null]);
    $placeholder = Device::factory()->for($user)->placeholder()->create(['name' => null]);
    AccountProvisionedGrant::factory()->for($account)->for($named)->claimed()->create();
    AccountProvisionedGrant::factory()->for($account)->for($named)->revoked()->create();

    Livewire::actingAs($admin)
        ->test(DevicesRelationManager::class, ['ownerRecord' => $user, 'pageClass' => ViewUser::class])
        ->assertCanSeeTableRecords([$named, $unnamed, $placeholder])
        ->assertTableColumnStateSet('name', 'Alice Laptop', record: $named)
        ->assertTableColumnStateSet('name', 'Unnamed device', record: $unnamed)
        ->assertTableColumnStateSet('name', 'Awaiting device', record: $placeholder)
        ->assertTableColumnStateSet('grants_count', 2, record: $named)
        ->assertTableColumnStateSet('grants_count', 0, record: $unnamed);
});

it('only shows the devices tab on the user view page', function () {
    $ownerRecord = User::factory()->create();

    expect(DevicesRelationManager::canViewForRecord($ownerRecord, ViewUser::class))->toBeTrue()
        ->and(DevicesRelationManager::canViewForRecord($ownerRecord, EditUser::class))->toBeFalse();
});

it('renames a device, prefilling the current name', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $device = Device::factory()->for($user)->create(['device_id' => 'fp-1', 'name' => 'Old Name']);

    Livewire::actingAs($admin)
        ->test(DevicesRelationManager::class, ['ownerRecord' => $user, 'pageClass' => ViewUser::class])
        ->mountTableAction('rename', $device)
        ->assertTableActionDataSet(['name' => 'Old Name'])
        ->setTableActionData(['name' => 'New Name'])
        ->callMountedTableAction()
        ->assertNotified();

    expect($device->fresh()->name)->toBe('New Name');
});
