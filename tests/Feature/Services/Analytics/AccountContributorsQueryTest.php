<?php

use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\Event;
use App\Models\User;
use App\Services\Analytics\AccountContributorsQuery;
use App\Services\Analytics\UsageFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns all-time contributors per account with status and tokens, sorted by tokens desc', function () {
    $account = Account::factory()->create();
    $tracked = User::factory()->create(['slack_handle' => 'alpha']);
    $untracked = User::factory()->create(['slack_handle' => 'beta']);

    $account->users()->attach($tracked->id, ['status' => MembershipStatus::Tracked->value]);
    $account->users()->attach($untracked->id, ['status' => MembershipStatus::Untracked->value]);

    Event::factory()->for($tracked)->create(['account_id' => $account->id, 'tokens' => 300, 'created_at' => now()]);
    Event::factory()->for($untracked)->create(['account_id' => $account->id, 'tokens' => 700, 'created_at' => now()->subMonths(6)]);

    $byAccount = app(AccountContributorsQuery::class)->get();

    expect($byAccount)->toHaveKey($account->id);

    $members = $byAccount[$account->id];

    expect($members)->toHaveCount(2)
        ->and($members[0]['tokens'])->toBe(700)
        ->and($members[0]['status'])->toBe(MembershipStatus::Untracked->value)
        ->and($members[1]['tokens'])->toBe(300)
        ->and($members[1]['status'])->toBe(MembershipStatus::Tracked->value)
        ->and($members[1]['handle'])->toBe('alpha');
});

it('excludes events with no account and sums all-time regardless of when they occurred', function () {
    $account = Account::factory()->create();
    $user = User::factory()->create();
    $account->users()->attach($user->id, ['status' => MembershipStatus::Tracked->value]);

    Event::factory()->for($user)->create(['account_id' => $account->id, 'tokens' => 100, 'created_at' => now()->subYears(2)]);
    Event::factory()->for($user)->create(['account_id' => $account->id, 'tokens' => 50, 'created_at' => now()]);
    Event::factory()->for($user)->create(['account_id' => null, 'tokens' => 999, 'created_at' => now()]);

    $members = app(AccountContributorsQuery::class)->get()[$account->id];

    expect($members)->toHaveCount(1)
        ->and($members[0]['tokens'])->toBe(150);
});

it('windows tokens to the filter range', function () {
    $account = Account::factory()->create();
    $user = User::factory()->create();
    $account->users()->attach($user->id, ['status' => MembershipStatus::Tracked->value]);

    Event::factory()->for($user)->create(['account_id' => $account->id, 'tokens' => 40, 'created_at' => now()]);
    Event::factory()->for($user)->create(['account_id' => $account->id, 'tokens' => 900, 'created_at' => now()->subMonths(2)]);

    $filters = UsageFilters::fromPageFilters(['range' => 'week']);
    $members = app(AccountContributorsQuery::class)->get($filters)[$account->id];

    expect($members[0]['tokens'])->toBe(40);
});

it('in total-across-accounts mode shows the same user total in each of their account cards', function () {
    $accountA = Account::factory()->create();
    $accountB = Account::factory()->create();
    $user = User::factory()->create();
    $accountA->users()->attach($user->id, ['status' => MembershipStatus::Tracked->value]);
    $accountB->users()->attach($user->id, ['status' => MembershipStatus::Tracked->value]);

    Event::factory()->for($user)->create(['account_id' => $accountA->id, 'tokens' => 100, 'created_at' => now()]);
    Event::factory()->for($user)->create(['account_id' => $accountB->id, 'tokens' => 200, 'created_at' => now()]);

    $filters = UsageFilters::fromPageFilters(['range' => 'all']);
    $byAccount = app(AccountContributorsQuery::class)->get($filters, totalAcrossAccounts: true);

    expect($byAccount[$accountA->id][0]['tokens'])->toBe(300)
        ->and($byAccount[$accountB->id][0]['tokens'])->toBe(300);

    // Default (per-account) mode keeps each card scoped to its own attribution.
    $perAccount = app(AccountContributorsQuery::class)->get($filters);
    expect($perAccount[$accountA->id][0]['tokens'])->toBe(100)
        ->and($perAccount[$accountB->id][0]['tokens'])->toBe(200);
});
