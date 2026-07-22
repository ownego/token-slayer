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

it('sums each account\'s attributed tokens (per-account, toggle-independent), windowed', function () {
    $accountA = Account::factory()->create();
    $accountB = Account::factory()->create();
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    Event::factory()->for($userA)->create(['account_id' => $accountA->id, 'tokens' => 100, 'created_at' => now()]);
    Event::factory()->for($userB)->create(['account_id' => $accountA->id, 'tokens' => 200, 'created_at' => now()]);
    Event::factory()->for($userB)->create(['account_id' => $accountB->id, 'tokens' => 50, 'created_at' => now()]);
    Event::factory()->for($userA)->create(['account_id' => $accountA->id, 'tokens' => 900, 'created_at' => now()->subMonths(2)]);

    $filters = UsageFilters::fromPageFilters(['range' => 'week']);
    $totals = app(AccountContributorsQuery::class)->accountTotals($filters);

    expect($totals[$accountA->id])->toBe(300)
        ->and($totals[$accountB->id])->toBe(50)
        ->and(array_sum($totals))->toBe(350);
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
    // Private / un-beaconed usage (no account attribution) still belongs to
    // this person, so the toggle must count it toward their total.
    Event::factory()->for($user)->create(['account_id' => null, 'tokens' => 500, 'created_at' => now()]);

    $filters = UsageFilters::fromPageFilters(['range' => 'all']);
    $byAccount = app(AccountContributorsQuery::class)->get($filters, totalAcrossAccounts: true);

    expect($byAccount[$accountA->id][0]['tokens'])->toBe(800)   // 100 + 200 + 500 (private)
        ->and($byAccount[$accountB->id][0]['tokens'])->toBe(800);

    // Default (per-account) mode keeps each card scoped to its own attribution.
    $perAccount = app(AccountContributorsQuery::class)->get($filters);
    expect($perAccount[$accountA->id][0]['tokens'])->toBe(100)
        ->and($perAccount[$accountB->id][0]['tokens'])->toBe(200);
});

it('still windows the total-across-accounts figure by the range', function () {
    $account = Account::factory()->create();
    $user = User::factory()->create();
    $account->users()->attach($user->id, ['status' => MembershipStatus::Tracked->value]);

    Event::factory()->for($user)->create(['account_id' => $account->id, 'tokens' => 10, 'created_at' => now()]);
    Event::factory()->for($user)->create(['account_id' => null, 'tokens' => 20, 'created_at' => now()]);
    Event::factory()->for($user)->create(['account_id' => null, 'tokens' => 9000, 'created_at' => now()->subMonths(2)]);

    $week = UsageFilters::fromPageFilters(['range' => 'week']);
    $members = app(AccountContributorsQuery::class)->get($week, totalAcrossAccounts: true)[$account->id];

    expect($members[0]['tokens'])->toBe(30); // 10 + 20 in range; the 2-month-old 9000 excluded
});

it('lists a tracked member with no attributed events: 0 tokens when off, whole usage when on', function () {
    $account = Account::factory()->create();
    $member = User::factory()->create(['slack_handle' => 'ghost']);
    $account->users()->attach($member->id, ['status' => MembershipStatus::Tracked->value]);

    // All of the member's usage is unattributed (no account_id) — none on $account.
    Event::factory()->for($member)->create(['account_id' => null, 'tokens' => 5000, 'created_at' => now()]);

    $off = app(AccountContributorsQuery::class)->get(null, false);
    expect($off[$account->id])->toHaveCount(1)
        ->and($off[$account->id][0]['user_id'])->toBe($member->id)
        ->and($off[$account->id][0]['status'])->toBe(MembershipStatus::Tracked->value)
        ->and($off[$account->id][0]['tokens'])->toBe(0);

    $on = app(AccountContributorsQuery::class)->get(null, true);
    expect($on[$account->id])->toHaveCount(1)
        ->and($on[$account->id][0]['tokens'])->toBe(5000);
});

it('shows tracked members alongside event contributors when across-accounts is on', function () {
    $account = Account::factory()->create();
    $contributor = User::factory()->create(['slack_handle' => 'worker']);
    $ghostMember = User::factory()->create(['slack_handle' => 'ghost']);

    $account->users()->attach($contributor->id, ['status' => MembershipStatus::Tracked->value]);
    $account->users()->attach($ghostMember->id, ['status' => MembershipStatus::Tracked->value]);

    Event::factory()->for($contributor)->create(['account_id' => $account->id, 'tokens' => 100, 'created_at' => now()]);
    Event::factory()->for($ghostMember)->create(['account_id' => null, 'tokens' => 9000, 'created_at' => now()]);

    $on = app(AccountContributorsQuery::class)->get(null, true)[$account->id];

    expect($on)->toHaveCount(2)
        ->and($on[0]['user_id'])->toBe($ghostMember->id)   // sorted by whole usage desc
        ->and($on[0]['tokens'])->toBe(9000)
        ->and($on[1]['user_id'])->toBe($contributor->id)
        ->and($on[1]['tokens'])->toBe(100);
});
