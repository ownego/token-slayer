<?php

use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('defaults a plain attach to tracked and casts the pivot status', function () {
    $account = Account::factory()->create();
    $user = User::factory()->create();
    $account->users()->attach($user);

    $pivotUser = $account->users()->first();
    expect($pivotUser->pivot->status)->toBe(MembershipStatus::Tracked);
});

it('separates tracked from untracked users', function () {
    $account = Account::factory()->create();
    $tracked = User::factory()->create();
    $untracked = User::factory()->create();
    $account->users()->attach($tracked, ['status' => MembershipStatus::Tracked->value]);
    $account->users()->attach($untracked, ['status' => MembershipStatus::Untracked->value]);

    expect($account->trackedUsers()->pluck('users.id')->all())->toBe([$tracked->id]);
    expect($account->untrackedUsers()->pluck('users.id')->all())->toBe([$untracked->id]);
});

it('exposes a user\'s tracked accounts only', function () {
    $user = User::factory()->create();
    $trackedAccount = Account::factory()->create();
    $untrackedAccount = Account::factory()->create();
    $trackedAccount->users()->attach($user, ['status' => MembershipStatus::Tracked->value]);
    $untrackedAccount->users()->attach($user, ['status' => MembershipStatus::Untracked->value]);

    expect($user->trackedAccounts()->pluck('accounts.id')->all())->toBe([$trackedAccount->id]);
});
