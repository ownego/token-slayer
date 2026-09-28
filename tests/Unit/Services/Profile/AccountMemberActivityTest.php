<?php

use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\Event;
use App\Models\User;
use App\Services\Profile\AccountMemberActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('joins each tracked member with their damage/events today and last-seen time, scoped to this account', function () {
    $account = Account::factory()->create();
    $member = User::factory()->create();
    $account->users()->syncWithoutDetaching([$member->id => ['status' => MembershipStatus::Tracked->value]]);

    Event::factory()->for($member)->for($account)->create(['tokens' => 300, 'created_at' => now()]);
    Event::factory()->for($member)->for($account)->create(['tokens' => 200, 'created_at' => now()]);
    // A different account's event for the same user must not count here.
    $otherAccount = Account::factory()->create();
    Event::factory()->for($member)->for($otherAccount)->create(['tokens' => 999, 'created_at' => now()]);
    // Yesterday's event must not count toward "today".
    Event::factory()->for($member)->for($account)->create(['tokens' => 500, 'created_at' => now()->subDay()]);

    $rows = app(AccountMemberActivity::class)->for($account->fresh());

    expect($rows)->toHaveCount(1);
    expect($rows[0]['user_id'])->toBe($member->id);
    expect($rows[0]['damage_today'])->toBe(500);
    expect($rows[0]['events_today'])->toBe(2);
    expect($rows[0]['last_seen_at']->toDateString())->toBe(now()->toDateString());
});

test('a member with no events today reports zero damage/events and a null last-seen', function () {
    $account = Account::factory()->create();
    $member = User::factory()->create();
    $account->users()->syncWithoutDetaching([$member->id => ['status' => MembershipStatus::Tracked->value]]);

    $rows = app(AccountMemberActivity::class)->for($account->fresh());

    expect($rows[0]['damage_today'])->toBe(0);
    expect($rows[0]['events_today'])->toBe(0);
    expect($rows[0]['last_seen_at'])->toBeNull();
});

test('a member active only yesterday shows zero damage/events today but their real last-seen time, not "never"', function () {
    $account = Account::factory()->create();
    $member = User::factory()->create();
    $account->users()->syncWithoutDetaching([$member->id => ['status' => MembershipStatus::Tracked->value]]);

    $yesterday = now()->subDay();
    Event::factory()->for($member)->for($account)->create(['tokens' => 500, 'created_at' => $yesterday]);

    $rows = app(AccountMemberActivity::class)->for($account->fresh());

    expect($rows[0]['damage_today'])->toBe(0);
    expect($rows[0]['events_today'])->toBe(0);
    expect($rows[0]['last_seen_at'])->not->toBeNull();
    expect($rows[0]['last_seen_at']->toDateString())->toBe($yesterday->toDateString());
});

test('an account with no members returns an empty array', function () {
    $account = Account::factory()->create();

    $rows = app(AccountMemberActivity::class)->for($account);

    expect($rows)->toBe([]);
});
