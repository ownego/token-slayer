<?php

use App\Enums\MembershipStatus;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\RelationManagers\AccountsRelationManager;
use App\Models\Account;
use App\Models\User;
use App\Support\CacheKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

it('points each row at the account\'s own view page', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $account = Account::factory()->create(['email' => 'org@example.com']);
    $user->accounts()->attach($account->id, ['status' => MembershipStatus::Tracked->value]);

    $component = Livewire::actingAs($admin)
        ->test(AccountsRelationManager::class, ['ownerRecord' => $user, 'pageClass' => ViewUser::class]);

    $recordUrl = $component->instance()->getTable()->getRecordUrl($account);

    expect($recordUrl)->toBe(AccountResource::getUrl('view', ['record' => $account]));
});

it('verifies an untracked membership, promoting it to tracked and forgetting the account\'s membership cache', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $account = Account::factory()->create();
    $user->accounts()->attach($account->id, ['status' => MembershipStatus::Untracked->value]);
    Cache::put(CacheKeys::trackedMembers($account->id), ['stale'], 60);

    Livewire::actingAs($admin)
        ->test(AccountsRelationManager::class, ['ownerRecord' => $user, 'pageClass' => ViewUser::class])
        ->callTableAction('verify', $account)
        ->assertNotified();

    expect($account->trackedUsers()->whereKey($user->id)->exists())->toBeTrue();
    expect(Cache::has(CacheKeys::trackedMembers($account->id)))->toBeFalse();
});

it('verifies a pending membership, promoting it to tracked', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $account = Account::factory()->create();
    $user->accounts()->attach($account->id, ['status' => MembershipStatus::Pending->value]);

    Livewire::actingAs($admin)
        ->test(AccountsRelationManager::class, ['ownerRecord' => $user, 'pageClass' => ViewUser::class])
        ->callTableAction('verify', $account);

    expect($account->trackedUsers()->whereKey($user->id)->exists())->toBeTrue();
});

it('demotes a tracked membership to untracked, keeping the row, and forgets the account\'s membership cache', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $account = Account::factory()->create();
    $user->accounts()->attach($account->id, ['status' => MembershipStatus::Tracked->value]);
    Cache::put(CacheKeys::trackedMembers($account->id), ['stale'], 60);

    Livewire::actingAs($admin)
        ->test(AccountsRelationManager::class, ['ownerRecord' => $user, 'pageClass' => ViewUser::class])
        ->callTableAction('unverify', $account)
        ->assertNotified();

    expect($account->trackedUsers()->whereKey($user->id)->exists())->toBeFalse();
    expect($account->untrackedUsers()->whereKey($user->id)->exists())->toBeTrue();
    expect(Cache::has(CacheKeys::trackedMembers($account->id)))->toBeFalse();
});

it('hides verify for a tracked membership and hides unverify for an untracked membership', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $trackedAccount = Account::factory()->create();
    $untrackedAccount = Account::factory()->create();
    $user->accounts()->attach($trackedAccount->id, ['status' => MembershipStatus::Tracked->value]);
    $user->accounts()->attach($untrackedAccount->id, ['status' => MembershipStatus::Untracked->value]);

    $component = Livewire::actingAs($admin)
        ->test(AccountsRelationManager::class, ['ownerRecord' => $user, 'pageClass' => ViewUser::class]);

    $component->assertTableActionHidden('verify', $trackedAccount)
        ->assertTableActionVisible('unverify', $trackedAccount)
        ->assertTableActionVisible('verify', $untrackedAccount)
        ->assertTableActionHidden('unverify', $untrackedAccount);
});
