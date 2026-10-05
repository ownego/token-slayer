<?php

use App\Enums\GrantStatus;
use App\Enums\MembershipStatus;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\RelationManagers\MembersRelationManager;
use App\Models\Account;
use App\Models\AccountProvisionedGrant;
use App\Models\AccountReserveToken;
use App\Models\CodexCredential;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('lists member devices with days left and reissues one from the reserve pool', function (): void {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $member = User::factory()->create(['email' => 'alice@example.com']);
    $account->users()->attach($member->id, ['status' => MembershipStatus::Tracked->value]);
    $device = Device::factory()->for($member)->create(['device_id' => 'fp-a', 'name' => 'Alice Mac']);
    $grant = AccountProvisionedGrant::factory()->for($account)->for($device)->claimed()->create(['session_expires_at' => now()->subDays(3)->subHour()]);
    $reserve = AccountReserveToken::factory()->for($account)->create();

    Livewire::actingAs($admin)
        ->test(MembersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->mountAction('devices')
        ->assertMountedActionModalSee(['alice@example.com', 'Alice Mac', '3d overdue'])
        ->mountAction('reissueGrant', ['grant' => $grant->id])
        ->assertActionMounted('confirmReissue')
        ->callMountedAction()
        ->assertNotified('Grant reissued');

    expect($grant->fresh()->status)->toBe(GrantStatus::Revoked)
        ->and($reserve->fresh()->used_at)->not->toBeNull();
});

it('hides the Devices button on a Codex account', function (): void {
    $admin = User::factory()->admin()->create();
    $account = Account::create(['email' => 'codex@example.com', 'provider' => 'codex', 'name' => 'Codex']);
    CodexCredential::create(['account_id' => $account->id, 'chatgpt_account_id' => 'acct-1']);

    Livewire::actingAs($admin)
        ->test(MembersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->assertActionHidden('devices');
});

it('lists a not-yet-set-up device, reissues it, and hides untracked members', function (): void {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $member = User::factory()->create(['email' => 'bob@example.com']);
    $stranger = User::factory()->create(['email' => 'stranger@example.com']);
    $account->users()->attach([
        $member->id => ['status' => MembershipStatus::Pending->value],
        $stranger->id => ['status' => MembershipStatus::Untracked->value],
    ]);
    $grant = AccountProvisionedGrant::factory()->for($account)
        ->for(Device::factory()->for($member)->create(['name' => 'Bob PC']))->pending()->create();
    AccountProvisionedGrant::factory()->for($account)
        ->for(Device::factory()->for($stranger))->claimed()->create();
    AccountReserveToken::factory()->for($account)->create();

    Livewire::actingAs($admin)
        ->test(MembersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->mountAction('devices')
        ->assertMountedActionModalSee(['bob@example.com', 'Bob PC', 'Not set up'])
        ->assertMountedActionModalDontSee('stranger@example.com')
        ->mountAction('reissueGrant', ['grant' => $grant->id])
        ->assertActionMounted('confirmReissue')
        ->callMountedAction()
        ->assertNotified('Grant reissued');

    expect($grant->fresh()->status)->toBe(GrantStatus::Revoked);
});
