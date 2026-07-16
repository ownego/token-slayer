<?php

use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\Event;
use App\Models\User;
use App\Services\Accounts\AccountMembershipCache;
use App\Support\CacheKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

it('aggregates tracked members with event count and last seen', function () {
    $account = Account::factory()->create();
    $member = User::factory()->create();
    $account->users()->attach($member, ['status' => MembershipStatus::Tracked->value]);
    Event::factory()->for($member)->for($account)->create(['created_at' => now()->subDay()]);
    $latest = Event::factory()->for($member)->for($account)->create(['created_at' => now()->subHour()]);

    $map = app(AccountMembershipCache::class)->trackedAggregates($account);

    expect($map[$member->id]['events'])->toBe(2);
    expect($map[$member->id]['last_seen'])->toBe((string) $latest->created_at);
});

it('aggregates untracked contributors with event count and last seen', function () {
    $account = Account::factory()->create();
    $contributor = User::factory()->create();
    $account->users()->attach($contributor, ['status' => MembershipStatus::Untracked->value]);
    Event::factory()->count(3)->for($contributor)->for($account)->create();

    $member = User::factory()->create();
    $account->users()->attach($member, ['status' => MembershipStatus::Tracked->value]);
    Event::factory()->for($member)->for($account)->create();

    $map = app(AccountMembershipCache::class)->untrackedAggregates($account);

    expect(array_keys($map))->toBe([$contributor->id]);
    expect($map[$contributor->id]['events'])->toBe(3);
});

it('merges tracked and untracked aggregates into one contributor map', function () {
    $account = Account::factory()->create();
    $member = User::factory()->create();
    $account->users()->attach($member, ['status' => MembershipStatus::Tracked->value]);
    Event::factory()->count(2)->for($member)->for($account)->create();

    $contributor = User::factory()->create();
    $account->users()->attach($contributor, ['status' => MembershipStatus::Untracked->value]);
    Event::factory()->count(3)->for($contributor)->for($account)->create();

    $map = app(AccountMembershipCache::class)->allContributorAggregates($account);

    expect(array_keys($map))->toEqualCanonicalizing([$member->id, $contributor->id]);
    expect($map[$member->id]['events'])->toBe(2);
    expect($map[$contributor->id]['events'])->toBe(3);
});

it('caches under the account keys', function () {
    $account = Account::factory()->create();
    app(AccountMembershipCache::class)->trackedAggregates($account);
    app(AccountMembershipCache::class)->untrackedAggregates($account);

    expect(Cache::has(CacheKeys::trackedMembers($account->id)))->toBeTrue();
    expect(Cache::has(CacheKeys::untrackedContributors($account->id)))->toBeTrue();
});
