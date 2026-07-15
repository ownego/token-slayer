<?php

use App\Enums\MembershipStatus;
use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Resources\Accounts\RelationManagers\UsersRelationManager;
use App\Models\Account;
use App\Models\User;
use App\Services\Accounts\AccountMembershipCache;
use App\Support\CacheKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows only tracked members', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $member = User::factory()->create();
    $account->users()->attach($member, ['status' => MembershipStatus::Tracked->value]);
    $contributor = User::factory()->create();
    $account->users()->attach($contributor, ['status' => MembershipStatus::Untracked->value]);

    Livewire::actingAs($admin)
        ->test(UsersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => ViewAccount::class])
        ->assertOk()
        ->assertCanSeeTableRecords([$member])
        ->assertCanNotSeeTableRecords([$contributor]);
});

it('demotes a member to untracked keeping the row, and forgets the cache', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $member = User::factory()->create();
    $account->users()->attach($member, ['status' => MembershipStatus::Tracked->value]);
    app(AccountMembershipCache::class)->trackedLastSeen($account);
    expect(Cache::has(CacheKeys::trackedMembers($account->id)))->toBeTrue();

    Livewire::actingAs($admin)
        ->test(UsersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => ViewAccount::class])
        ->callTableAction('removeFromTracking', record: $member);

    expect($account->trackedUsers()->whereKey($member->id)->exists())->toBeFalse();
    expect($account->untrackedUsers()->whereKey($member->id)->exists())->toBeTrue();
    expect(Cache::has(CacheKeys::trackedMembers($account->id)))->toBeFalse();
});

it('refreshes by forgetting the membership caches', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    app(AccountMembershipCache::class)->trackedLastSeen($account);

    Livewire::actingAs($admin)
        ->test(UsersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => ViewAccount::class])
        ->callTableAction('refresh');

    expect(Cache::has(CacheKeys::trackedMembers($account->id)))->toBeFalse();
});
