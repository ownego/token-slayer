<?php

use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\User;
use App\Services\Accounts\AccountMemberStatusQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('includes tracked and pending members by default, but not untracked', function () {
    $account = Account::factory()->create();
    $tracked = User::factory()->create();
    $pending = User::factory()->create();
    $untracked = User::factory()->create();
    $account->users()->syncWithoutDetaching([
        $tracked->id => ['status' => MembershipStatus::Tracked->value],
        $pending->id => ['status' => MembershipStatus::Pending->value],
        $untracked->id => ['status' => MembershipStatus::Untracked->value],
    ]);

    $rows = app(AccountMemberStatusQuery::class)->get($account);

    expect(collect($rows)->pluck('status', 'user_id')->all())->toEqualCanonicalizing([
        $tracked->id => 'tracked',
        $pending->id => 'pending',
    ]);
});

it('includes untracked members too when $includeUntracked is true', function () {
    $account = Account::factory()->create();
    $tracked = User::factory()->create();
    $untracked = User::factory()->create();
    $account->users()->syncWithoutDetaching([
        $tracked->id => ['status' => MembershipStatus::Tracked->value],
        $untracked->id => ['status' => MembershipStatus::Untracked->value],
    ]);

    $rows = app(AccountMemberStatusQuery::class)->get($account, includeUntracked: true);

    expect(collect($rows)->pluck('status', 'user_id')->all())->toEqualCanonicalizing([
        $tracked->id => 'tracked',
        $untracked->id => 'untracked',
    ]);
});
