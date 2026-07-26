<?php

use App\Enums\GrantStatus;
use App\Enums\MembershipStatus;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\RelationManagers\MembersRelationManager;
use App\Models\Account;
use App\Models\AccountProvisionedGrant;
use App\Models\AccountUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('provisions the first grant onto a fresh placeholder device', function () {
    fakeAnthropic();
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create(['email' => 'ongtung2212002@gmail.com']);
    $newcomer = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(MembersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->mountAction('addMember')
        ->setActionData(['user_id' => $newcomer->id, 'provision' => true])
        ->callMountedAction()
        ->assertActionMounted('confirmProvisionMember')
        ->setActionData(['code' => 'pasted-code'])
        ->callMountedAction()
        ->assertNotified();

    $device = $newcomer->devices()->firstOrFail();
    $grant = AccountProvisionedGrant::query()
        ->where('account_id', $account->id)->where('device_id', $device->id)->firstOrFail();

    expect($device->device_id)->toBeNull()
        ->and($grant->status)->toBe(GrantStatus::Pending)
        ->and($newcomer->accounts()->find($account->id)->pivot->status)->toBe(MembershipStatus::Pending);
});

it('provisions an account for the new member when the toggle is on, landing at pending', function () {
    fakeAnthropic();
    $admin = User::factory()->admin()->create();
    // Profile fixture authorizes ongtung2212002@gmail.com — the account's
    // identity must match or provisionForDevice() rejects the exchange.
    $account = Account::factory()->create(['email' => 'ongtung2212002@gmail.com']);
    $newcomer = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(MembersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->mountAction('addMember')
        ->setActionData(['user_id' => $newcomer->id, 'provision' => true])
        ->callMountedAction()
        ->assertActionMounted('confirmProvisionMember')
        ->setActionData(['code' => 'pasted-code'])
        ->callMountedAction()
        ->assertNotified();

    $pivot = AccountUser::query()
        ->where('user_id', $newcomer->id)->where('account_id', $account->id)->firstOrFail();
    // Provisioning audit now lives per-grant (see AccountProvisionedGrant),
    // not on the account_user pivot.
    $grant = AccountProvisionedGrant::query()
        ->where('account_id', $account->id)
        ->whereHas('device', fn ($query) => $query->where('user_id', $newcomer->id))
        ->firstOrFail();

    expect($pivot->status)->toBe(MembershipStatus::Pending)
        ->and($grant->provisioned_at)->not->toBeNull();
});

it('adds the member as tracked without provisioning when the toggle is off', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $newcomer = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(MembersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->mountAction('addMember')
        ->setActionData(['user_id' => $newcomer->id, 'provision' => false])
        ->callMountedAction()
        ->assertNotified()
        ->assertActionNotMounted('confirmProvisionMember');

    $pivot = AccountUser::query()
        ->where('user_id', $newcomer->id)->where('account_id', $account->id)->firstOrFail();

    expect($pivot->status)->toBe(MembershipStatus::Tracked)
        ->and($pivot->provisioned_at)->toBeNull();
});
