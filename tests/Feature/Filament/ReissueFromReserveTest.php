<?php

use App\Enums\GrantStatus;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\RelationManagers\ProvisionsRelationManager;
use App\Models\Account;
use App\Models\AccountProvisionedGrant;
use App\Models\AccountReserveToken;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('reissues a grant from the reserve pool without pasting a code', function (): void {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $device = Device::factory()->for(User::factory())->create(['device_id' => 'fp-1']);
    $old = AccountProvisionedGrant::factory()->for($account)->for($device)->claimed()->create();
    AccountReserveToken::factory()->for($account)->create(['session_expires_at' => now()->addDays(20)]);
    $soonest = AccountReserveToken::factory()->for($account)->create(['session_expires_at' => now()->addDays(4)]);

    Livewire::actingAs($admin)
        ->test(ProvisionsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        // `reissue` has no modal of its own, so mounting it runs it at once
        // and leaves `confirmReissue` mounted.
        ->mountTableAction('reissue', record: $old)
        ->assertActionMounted('confirmReissue')
        ->assertActionDataSet(['source' => 'reserve', 'reserve_token_id' => $soonest->id])
        ->callMountedAction()
        ->assertNotified('Grant reissued');

    $new = AccountProvisionedGrant::query()->live()->where('device_id', $device->id)->firstOrFail();
    expect($old->fresh()->status)->toBe(GrantStatus::Revoked)
        ->and($soonest->fresh()->used_grant_id)->toBe($new->id);
});

it('defaults to paste code when the pool is empty', function (): void {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $old = AccountProvisionedGrant::factory()->for($account)->claimed()->create();

    Livewire::actingAs($admin)
        ->test(ProvisionsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->mountTableAction('reissue', record: $old)
        ->assertActionDataSet(['source' => 'paste', 'reserve_token_id' => null]);
});

it('rejects a reserve token used by someone else while the modal was open', function (): void {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $old = AccountProvisionedGrant::factory()->for($account)->claimed()->create();
    $reserve = AccountReserveToken::factory()->for($account)->create();

    $component = Livewire::actingAs($admin)
        ->test(ProvisionsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->mountTableAction('reissue', record: $old);
    $reserve->update(['used_at' => now()]);

    // The picker re-reads the pool on submit, so the gone token fails
    // validation on the field; the service-level lock behind it is covered
    // in ProvisionFromReserveTest.
    $component->callMountedAction()->assertHasActionErrors(['reserve_token_id']);

    expect($old->fresh()->status)->toBe(GrantStatus::Claimed);
});
