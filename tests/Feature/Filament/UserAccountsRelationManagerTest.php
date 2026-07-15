<?php

use App\Enums\MembershipStatus;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\RelationManagers\AccountsRelationManager;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('lists the accounts this user is a member of', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $account = Account::factory()->create(['email' => 'org@example.com']);
    $user->accounts()->attach($account->id, ['status' => MembershipStatus::Tracked->value]);

    Livewire::actingAs($admin)
        ->test(AccountsRelationManager::class, ['ownerRecord' => $user, 'pageClass' => ViewUser::class])
        ->assertOk()
        ->assertCanSeeTableRecords([$account]);
});
