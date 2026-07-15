<?php

use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\User;
use App\Services\DamageTotals;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('counts only tracked members in perAccount', function () {
    $account = Account::factory()->create();
    $tracked = User::factory()->create();
    $untracked = User::factory()->create();
    $account->users()->attach($tracked, ['status' => MembershipStatus::Tracked->value]);
    $account->users()->attach($untracked, ['status' => MembershipStatus::Untracked->value]);

    $row = collect(app(DamageTotals::class)->perAccount())->firstWhere('account_id', $account->id);

    expect($row['memberCount'])->toBe(1);
});

it('does not treat an untracked-only user as assigned', function () {
    $account = Account::factory()->create();
    $user = User::factory()->create();
    $account->users()->attach($user, ['status' => MembershipStatus::Untracked->value]);

    $unassigned = collect(app(DamageTotals::class)->perAccount())->firstWhere('account_id', null);

    expect($unassigned['memberCount'])->toBe(1);
});
