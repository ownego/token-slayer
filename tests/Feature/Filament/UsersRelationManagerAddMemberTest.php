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

it('adds a brand-new user directly as a tracked member', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $newcomer = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(UsersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => ViewAccount::class])
        ->callTableAction('addMember', data: ['user_id' => $newcomer->id])
        ->assertNotified();

    expect($account->trackedUsers()->whereKey($newcomer->id)->exists())->toBeTrue();
});

it('promotes an existing untracked contributor without a duplicate-key error', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $contributor = User::factory()->create();
    $account->users()->attach($contributor, ['status' => MembershipStatus::Untracked->value]);
    app(AccountMembershipCache::class)->trackedLastSeen($account);
    expect(Cache::has(CacheKeys::trackedMembers($account->id)))->toBeTrue();

    Livewire::actingAs($admin)
        ->test(UsersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => ViewAccount::class])
        ->callTableAction('addMember', data: ['user_id' => $contributor->id]);

    expect($account->trackedUsers()->whereKey($contributor->id)->exists())->toBeTrue();
    expect($account->untrackedUsers()->whereKey($contributor->id)->exists())->toBeFalse();
    expect($account->users()->whereKey($contributor->id)->count())->toBe(1);
    expect(Cache::has(CacheKeys::trackedMembers($account->id)))->toBeFalse();
});
