<?php

use App\Enums\MembershipStatus;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\RelationManagers\MembersRelationManager;
use App\Models\Account;
use App\Models\Event;
use App\Models\User;
use App\Services\Accounts\AccountMembershipCache;
use App\Support\CacheKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('lists tracked and untracked contributors with status and toggles them', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $tracked = User::factory()->create();
    $untracked = User::factory()->create();
    $account->users()->attach($tracked->id, ['status' => MembershipStatus::Tracked->value]);
    $account->users()->attach($untracked->id, ['status' => MembershipStatus::Untracked->value]);

    Livewire::actingAs($admin)
        ->test(MembersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->assertCanSeeTableRecords([$tracked, $untracked])
        ->assertSee('Verified')
        ->assertSee('Unverified')
        ->callTableAction('verify', $untracked);

    expect($account->trackedUsers()->whereKey($untracked->id)->exists())->toBeTrue();
});

it('verifies an untracked contributor, dropping them from untrackedUsers() and forgetting the untracked cache', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $contributor = User::factory()->create();
    $account->users()->attach($contributor, ['status' => MembershipStatus::Untracked->value]);
    app(AccountMembershipCache::class)->untrackedAggregates($account);
    expect(Cache::has(CacheKeys::untrackedContributors($account->id)))->toBeTrue();

    Livewire::actingAs($admin)
        ->test(MembersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->callTableAction('verify', $contributor);

    expect($account->trackedUsers()->whereKey($contributor->id)->exists())->toBeTrue();
    expect($account->untrackedUsers()->whereKey($contributor->id)->exists())->toBeFalse();
    expect(Cache::has(CacheKeys::untrackedContributors($account->id)))->toBeFalse();
});

it('demotes a tracked member to untracked keeping the row, and forgets the cache', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $member = User::factory()->create();
    $account->users()->attach($member, ['status' => MembershipStatus::Tracked->value]);
    app(AccountMembershipCache::class)->trackedAggregates($account);
    expect(Cache::has(CacheKeys::trackedMembers($account->id)))->toBeTrue();

    Livewire::actingAs($admin)
        ->test(MembersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->callTableAction('unverify', record: $member)
        ->assertNotified();

    expect($account->trackedUsers()->whereKey($member->id)->exists())->toBeFalse();
    expect($account->untrackedUsers()->whereKey($member->id)->exists())->toBeTrue();
    expect(Cache::has(CacheKeys::trackedMembers($account->id)))->toBeFalse();
});

it('refreshes by forgetting both membership caches', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    app(AccountMembershipCache::class)->trackedAggregates($account);
    app(AccountMembershipCache::class)->untrackedAggregates($account);

    Livewire::actingAs($admin)
        ->test(MembersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->callTableAction('refresh')
        ->assertNotified();

    expect(Cache::has(CacheKeys::trackedMembers($account->id)))->toBeFalse();
    expect(Cache::has(CacheKeys::untrackedContributors($account->id)))->toBeFalse();
});

it('adds a brand-new user directly as a tracked member when the provision toggle is off', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $newcomer = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(MembersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->callTableAction('addMember', data: ['user_id' => $newcomer->id, 'provision' => false])
        ->assertNotified();

    expect($account->trackedUsers()->whereKey($newcomer->id)->exists())->toBeTrue();
});

it('promotes an existing untracked contributor via addMember without a duplicate-key error', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $contributor = User::factory()->create();
    $account->users()->attach($contributor, ['status' => MembershipStatus::Untracked->value]);
    app(AccountMembershipCache::class)->trackedAggregates($account);
    expect(Cache::has(CacheKeys::trackedMembers($account->id)))->toBeTrue();

    Livewire::actingAs($admin)
        ->test(MembersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->callTableAction('addMember', data: ['user_id' => $contributor->id, 'provision' => false]);

    expect($account->trackedUsers()->whereKey($contributor->id)->exists())->toBeTrue();
    expect($account->untrackedUsers()->whereKey($contributor->id)->exists())->toBeFalse();
    expect($account->users()->whereKey($contributor->id)->count())->toBe(1);
    expect(Cache::has(CacheKeys::trackedMembers($account->id)))->toBeFalse();
});

it('displays the cached last-seen time for a tracked member', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $member = User::factory()->create();
    $account->users()->attach($member, ['status' => MembershipStatus::Tracked->value]);
    Event::factory()->for($member)->for($account)->create(['created_at' => now()->subDay()]);
    $latest = Event::factory()->for($member)->for($account)->create(['created_at' => now()->subHour()]);

    Livewire::actingAs($admin)
        ->test(MembersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->assertTableColumnStateSet('last_seen', (string) $latest->created_at, $member)
        ->assertTableColumnStateSet('events', 2, $member);
});

it('displays the cached event count and last-seen time for an untracked contributor', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $contributor = User::factory()->create();
    $account->users()->attach($contributor, ['status' => MembershipStatus::Untracked->value]);
    Event::factory()->count(2)->for($contributor)->for($account)->create(['created_at' => now()->subDay()]);
    $latest = Event::factory()->for($contributor)->for($account)->create(['created_at' => now()->subHour()]);

    Livewire::actingAs($admin)
        ->test(MembersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->assertTableColumnStateSet('events', 3, $contributor)
        ->assertTableColumnStateSet('last_seen', (string) $latest->created_at, $contributor);
});

it('renders the "Pending setup" badge for a pending member', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $pending = User::factory()->create();
    $account->users()->attach($pending, ['status' => MembershipStatus::Pending->value]);

    Livewire::actingAs($admin)
        ->test(MembersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->assertSee('Pending setup');
});

it('verifies a pending member, flipping their pivot status to tracked (not a status-filtered no-op)', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $pending = User::factory()->create();
    $account->users()->attach($pending, ['status' => MembershipStatus::Pending->value]);

    Livewire::actingAs($admin)
        ->test(MembersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->callTableAction('verify', $pending);

    $pivot = $account->users()->whereKey($pending->id)->first()->pivot;
    expect($pivot->status)->toBe(MembershipStatus::Tracked);
});

it('renders a member identity even when slack_handle is null', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $member = User::factory()->create(['name' => 'Tung Ot', 'slack_handle' => null, 'display_name' => null]);
    $account->users()->attach($member, ['status' => MembershipStatus::Tracked->value]);

    Livewire::actingAs($admin)
        ->test(MembersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->assertSee('Tung Ot');
});
