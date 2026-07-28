<?php

use App\Enums\MembershipStatus;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\RelationManagers\MembersRelationManager;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('hides unverified members by default and reveals them via the filter', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $verified = User::factory()->create();
    $unverified = User::factory()->create();
    $account->users()->syncWithoutDetaching([
        $verified->id => ['status' => MembershipStatus::Tracked->value],
        $unverified->id => ['status' => MembershipStatus::Untracked->value],
    ]);

    Livewire::actingAs($admin)
        ->test(MembersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->assertCanSeeTableRecords([$verified])
        ->assertCanNotSeeTableRecords([$unverified])
        ->filterTable('unverified', true)
        ->assertCanSeeTableRecords([$verified, $unverified]);
});
