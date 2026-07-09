<?php

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('an account has many member users', function () {
    $account = Account::factory()->create(['email' => 'team-a@example.com', 'plan' => 'max-20x']);
    User::factory()->count(3)->create(['account_id' => $account->id]);
    User::factory()->create(); // unassigned

    expect($account->users)->toHaveCount(3)
        ->and($account->plan)->toBe('max-20x');
});

test('a user belongs to an account and account_id is nullable', function () {
    $account = Account::factory()->create();
    $member = User::factory()->create(['account_id' => $account->id]);
    $loner = User::factory()->create();

    expect($member->account->is($account))->toBeTrue()
        ->and($loner->account)->toBeNull();
});

test('deleting an account nulls its users account_id', function () {
    $account = Account::factory()->create();
    $user = User::factory()->create(['account_id' => $account->id]);

    $account->delete();

    expect($user->fresh()->account_id)->toBeNull();
});
