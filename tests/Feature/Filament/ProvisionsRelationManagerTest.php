<?php

use App\Enums\GrantStatus;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\RelationManagers\ProvisionsRelationManager;
use App\Models\Account;
use App\Models\AccountProvisionedGrant;
use App\Models\CodexCredential;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('hides Reissue for a Codex-provider grant row', function (): void {
    $admin = User::factory()->admin()->create();
    $account = Account::create(['email' => 'codex@example.com', 'provider' => 'codex', 'name' => 'Company ChatGPT']);
    CodexCredential::create(['account_id' => $account->id, 'chatgpt_account_id' => 'acct-1']);
    $user = User::factory()->create();
    $device = Device::factory()->for($user)->create(['device_id' => 'fp-1']);
    $grant = AccountProvisionedGrant::create(['account_id' => $account->id, 'device_id' => $device->id, 'status' => GrantStatus::Pending, 'provisioned_at' => now()]);

    Livewire::actingAs($admin)
        ->test(ProvisionsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->assertTableActionHidden('reissue', $grant);
});

it('shows Reissue for a Claude-provider grant row', function (): void {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $user = User::factory()->create();
    $device = Device::factory()->for($user)->create(['device_id' => 'fp-2']);
    $grant = AccountProvisionedGrant::create(['account_id' => $account->id, 'device_id' => $device->id, 'status' => GrantStatus::Pending, 'provisioned_at' => now()]);

    Livewire::actingAs($admin)
        ->test(ProvisionsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->assertTableActionVisible('reissue', $grant);
});
