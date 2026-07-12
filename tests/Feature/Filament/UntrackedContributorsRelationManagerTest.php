<?php

use App\Enums\MembershipStatus;
use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Resources\Accounts\RelationManagers\UntrackedContributorsRelationManager;
use App\Models\Account;
use App\Models\User;
use App\Services\Accounts\AccountMembershipCache;
use App\Support\CacheKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('lists untracked contributors and hides tracked members', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $account = Account::factory()->create();
    $member = User::factory()->create();
    $account->users()->attach($member, ['status' => MembershipStatus::Tracked->value]);
    $contributor = User::factory()->create();
    $account->users()->attach($contributor, ['status' => MembershipStatus::Untracked->value]);

    Livewire::actingAs($admin)
        ->test(UntrackedContributorsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => ViewAccount::class])
        ->assertOk()
        ->assertCanSeeTableRecords([$contributor])
        ->assertCanNotSeeTableRecords([$member]);
});

it('promotes a contributor to tracked and forgets the cache', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $account = Account::factory()->create();
    $contributor = User::factory()->create();
    $account->users()->attach($contributor, ['status' => MembershipStatus::Untracked->value]);
    app(AccountMembershipCache::class)->untrackedAggregates($account);
    expect(Cache::has(CacheKeys::untrackedContributors($account->id)))->toBeTrue();

    Livewire::actingAs($admin)
        ->test(UntrackedContributorsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => ViewAccount::class])
        ->callTableAction('attach', record: $contributor)
        ->assertNotified();

    expect($account->trackedUsers()->whereKey($contributor->id)->exists())->toBeTrue();
    expect($account->untrackedUsers()->whereKey($contributor->id)->exists())->toBeFalse();
    expect(Cache::has(CacheKeys::untrackedContributors($account->id)))->toBeFalse();
});

it('refreshes by forgetting the membership caches', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $account = Account::factory()->create();
    app(AccountMembershipCache::class)->untrackedAggregates($account);

    Livewire::actingAs($admin)
        ->test(UntrackedContributorsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => ViewAccount::class])
        ->callTableAction('refresh');

    expect(Cache::has(CacheKeys::untrackedContributors($account->id)))->toBeFalse();
});
